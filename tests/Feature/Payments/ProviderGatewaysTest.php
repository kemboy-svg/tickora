<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Exceptions\Payments\PaymentProviderException;
use App\Models\PaymentGatewaySetting;
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\Paystack\PaystackPaymentGateway;
use App\Services\Payments\Stripe\StripePaymentGateway;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPaymentProviders;
use Tests\TestCase;

class ProviderGatewaysTest extends TestCase
{
    use FakesPaymentProviders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderApis();
    }

    private function request(string $currency = 'MUR'): CheckoutRequest
    {
        return new CheckoutRequest(
            idempotencyKey: 'order:01abc:attempt:1',
            amount: Money::ofMinor(178500, $currency),
            orderReference: 'TKR-7Q4M-2KD9',
            customerEmail: 'asha@example.test',
            returnUrl: 'https://tickora.test/checkout/01abc',
            cancelUrl: 'https://tickora.test/checkout/01abc',
            metadata: ['order' => '01abc'],
        );
    }

    private function stripe(): StripePaymentGateway
    {
        $this->configureProvider(PaymentProvider::Stripe);
        $gateway = app(PaymentGatewayManager::class)->driver('stripe');
        $this->assertInstanceOf(StripePaymentGateway::class, $gateway);

        return $gateway;
    }

    private function paystack(): PaystackPaymentGateway
    {
        $this->configureProvider(PaymentProvider::Paystack);
        $gateway = app(PaymentGatewayManager::class)->driver('paystack');
        $this->assertInstanceOf(PaystackPaymentGateway::class, $gateway);

        return $gateway;
    }

    public function test_stripe_creates_a_hosted_checkout_session(): void
    {
        $session = $this->stripe()->createCheckout($this->request());

        $this->assertSame('cs_test_123', $session->providerPaymentId);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_123', $session->redirectUrl);
        $this->assertSame(PaymentStatus::Created, $session->status);

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $request->hasHeader('Authorization', 'Bearer '.self::STRIPE_SECRET)
                && $request->hasHeader('Idempotency-Key', 'order:01abc:attempt:1')
                && ($form['mode'] ?? null) === 'payment'
                && ($form['line_items'][0]['price_data']['currency'] ?? null) === 'mur'
                && ($form['line_items'][0]['price_data']['unit_amount'] ?? null) === '178500'
                && ($form['client_reference_id'] ?? null) === 'TKR-7Q4M-2KD9'
                && ($form['customer_email'] ?? null) === 'asha@example.test';
        });
    }

    public function test_stripe_verifies_webhook_signatures(): void
    {
        $gateway = $this->stripe();
        $object = ['id' => 'cs_test_123', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 178500, 'currency' => 'mur'];

        $hook = $this->stripeWebhook('checkout.session.completed', $object);
        $event = $gateway->verifyWebhook($hook['payload'], ['Stripe-Signature' => $hook['server']['HTTP_STRIPE_SIGNATURE']]);

        $this->assertSame('cs_test_123', $event->providerPaymentId);
        $this->assertSame(PaymentStatus::Succeeded, $event->status);
        $this->assertTrue(Money::ofMinor(178500, 'MUR')->equals($event->amount ?? Money::zero('MUR')));

        $forged = $this->stripeWebhook('checkout.session.completed', $object, secret: 'whsec_wrong');
        $this->expectException(InvalidWebhookSignature::class);
        $gateway->verifyWebhook($forged['payload'], ['Stripe-Signature' => $forged['server']['HTTP_STRIPE_SIGNATURE']]);
    }

    public function test_stripe_rejects_replayed_webhooks(): void
    {
        $gateway = $this->stripe();
        $hook = $this->stripeWebhook('checkout.session.completed', ['id' => 'cs_test_123'], now()->subMinutes(10)->getTimestamp());

        $this->expectException(InvalidWebhookSignature::class);
        $gateway->verifyWebhook($hook['payload'], ['stripe-signature' => $hook['server']['HTTP_STRIPE_SIGNATURE']]);
    }

    public function test_stripe_maps_session_states_and_refunds(): void
    {
        $gateway = $this->stripe();
        $this->stripeAmount = 178500;

        $this->assertSame(PaymentStatus::Created, $gateway->fetchPayment('cs_test_123')->status);

        $this->stripeSessionStatus = 'complete';
        $this->stripePaymentStatus = 'paid';
        $paid = $gateway->fetchPayment('cs_test_123');
        $this->assertSame(PaymentStatus::Succeeded, $paid->status);
        $this->assertSame(178500, $paid->amount->amount);
        $this->assertSame('MUR', $paid->amount->currency);

        $this->stripeSessionStatus = 'expired';
        $this->assertSame(PaymentStatus::Failed, $gateway->fetchPayment('cs_test_123')->status);

        $refund = $gateway->refund(new RefundRequest('cs_test_123', Money::ofMinor(178500, 'MUR'), 'refund:1'));
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.stripe.com/v1/refunds' && str_contains($r->body(), 'payment_intent=pi_123'));
    }

    public function test_stripe_errors_never_include_the_secret_key(): void
    {
        $this->configureProvider(PaymentProvider::Stripe);
        $setting = PaymentGatewaySetting::for(PaymentProvider::Stripe);
        $this->assertNotNull($setting);
        $setting->secret_key = 'sk_test_wrongkeywxyz';
        $setting->save();

        try {
            app(PaymentGatewayManager::class)->driver('stripe')->verifyCredentials();
            $this->fail('Expected an exception.');
        } catch (PaymentProviderException $e) {
            $this->assertStringContainsString('Invalid API Key', $e->getMessage());
            $this->assertStringNotContainsString('sk_test_wrongkeywxyz', $e->getMessage());
        }
    }

    public function test_paystack_initialises_a_transaction(): void
    {
        $session = $this->paystack()->createCheckout($this->request('KES'));

        $this->assertSame('https://checkout.paystack.com/abc123', $session->redirectUrl);
        $this->assertSame(PaystackPaymentGateway::referenceFor('order:01abc:attempt:1'), $session->providerPaymentId);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9.=-]+$/', $session->providerPaymentId);

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.paystack.co/transaction/initialize'
            && $r->hasHeader('Authorization', 'Bearer '.self::PAYSTACK_SECRET)
            && $r['amount'] === 178500
            && $r['currency'] === 'KES'
            && $r['email'] === 'asha@example.test'
            && $r['callback_url'] === 'https://tickora.test/checkout/01abc');
    }

    public function test_paystack_refuses_currencies_it_cannot_charge(): void
    {
        $this->expectException(PaymentProviderException::class);
        $this->paystack()->createCheckout($this->request('MUR'));
    }

    public function test_paystack_verifies_sha512_webhook_signatures(): void
    {
        $gateway = $this->paystack();
        $data = ['id' => 4099260516, 'reference' => 'TKR-abc', 'status' => 'success', 'amount' => 178500, 'currency' => 'KES'];

        $hook = $this->paystackWebhook('charge.success', $data);
        $event = $gateway->verifyWebhook($hook['payload'], ['x-paystack-signature' => $hook['server']['HTTP_X_PAYSTACK_SIGNATURE']]);

        $this->assertSame('TKR-abc', $event->providerPaymentId);
        $this->assertSame(PaymentStatus::Succeeded, $event->status);
        $this->assertSame('charge.success:4099260516', $event->providerEventId);

        $forged = $this->paystackWebhook('charge.success', $data, 'sk_test_attacker');
        $this->expectException(InvalidWebhookSignature::class);
        $gateway->verifyWebhook($forged['payload'], ['x-paystack-signature' => $forged['server']['HTTP_X_PAYSTACK_SIGNATURE']]);
    }

    public function test_paystack_maps_transaction_states_and_refunds(): void
    {
        $gateway = $this->paystack();
        $this->paystackAmount = 178500;

        $this->assertSame(PaymentStatus::Created, $gateway->fetchPayment('TKR-abc')->status);

        $this->paystackStatus = 'success';
        $this->assertSame(PaymentStatus::Succeeded, $gateway->fetchPayment('TKR-abc')->status);

        $this->paystackStatus = 'failed';
        $this->assertSame(PaymentStatus::Failed, $gateway->fetchPayment('TKR-abc')->status);

        $refund = $gateway->refund(new RefundRequest('TKR-abc', Money::ofMinor(178500, 'KES'), 'refund:1'));
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
    }

    public function test_unconfigured_providers_explain_themselves(): void
    {
        try {
            app(PaymentGatewayManager::class)->driver('stripe');
            $this->fail('Expected an exception.');
        } catch (PaymentProviderException $e) {
            $this->assertStringContainsString('Payment settings', $e->getMessage());
        }
    }
}
