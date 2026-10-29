<?php

namespace Tests\Feature\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Exceptions\Payments\PaymentProviderException;
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\Fake\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FakePaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tickora.payments.providers.fake.webhook_secret' => 'test-webhook-secret']);

        $gateway = app(PaymentGatewayManager::class)->driver('fake');
        $this->assertInstanceOf(FakePaymentGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    public function test_it_is_the_default_gateway_in_tests(): void
    {
        $this->assertInstanceOf(FakePaymentGateway::class, app(PaymentGateway::class));
        $this->assertSame('fake', app(PaymentGateway::class)->name());
    }

    public function test_checkout_sessions_are_idempotent(): void
    {
        $first = $this->gateway->createCheckout($this->checkoutRequest('checkout-1'));
        $again = $this->gateway->createCheckout($this->checkoutRequest('checkout-1'));
        $other = $this->gateway->createCheckout($this->checkoutRequest('checkout-2'));

        $this->assertSame($first->providerPaymentId, $again->providerPaymentId);
        $this->assertNotSame($first->providerPaymentId, $other->providerPaymentId);
        $this->assertSame(PaymentStatus::Created, $first->status);
        // Customers are sent to the sandbox's hosted payment screen.
        $this->assertStringEndsWith('/sandbox/payments/'.$first->providerPaymentId, $first->redirectUrl);
    }

    public function test_unsupported_currencies_are_refused(): void
    {
        $this->expectException(PaymentProviderException::class);

        $this->gateway->createCheckout(new CheckoutRequest(
            idempotencyKey: 'ugx',
            amount: Money::of('5000', 'UGX'),
            orderReference: 'TKR-AAAA-BBBB',
            customerEmail: 'amara@example.test',
            returnUrl: 'https://tickora.test/return',
            cancelUrl: 'https://tickora.test/cancel',
        ));
    }

    public function test_simulated_payments_produce_verifiable_webhooks(): void
    {
        $session = $this->gateway->createCheckout($this->checkoutRequest('checkout-1'));
        $webhook = $this->gateway->simulate($session->providerPaymentId, PaymentStatus::Succeeded);

        $event = $this->gateway->verifyWebhook($webhook['payload'], $webhook['headers']);

        $this->assertSame('fake', $event->provider);
        $this->assertSame('payment.succeeded', $event->type);
        $this->assertSame($session->providerPaymentId, $event->providerPaymentId);
        $this->assertSame(PaymentStatus::Succeeded, $event->status);
        $this->assertTrue($event->amount?->equals(Money::of('4335.75', 'MUR')));
        $this->assertTrue($event->isPaymentUpdate());

        $fetched = $this->gateway->fetchPayment($session->providerPaymentId);
        $this->assertSame(PaymentStatus::Succeeded, $fetched->status);
    }

    public function test_header_names_are_case_insensitive_and_may_be_arrays(): void
    {
        $session = $this->gateway->createCheckout($this->checkoutRequest('checkout-1'));
        $webhook = $this->gateway->simulate($session->providerPaymentId, PaymentStatus::Failed, 'insufficient_funds');

        $event = $this->gateway->verifyWebhook($webhook['payload'], [
            'Tickora-Fake-Signature' => [$webhook['headers'][FakePaymentGateway::SIGNATURE_HEADER]],
        ]);

        $this->assertSame(PaymentStatus::Failed, $event->status);
        $this->assertSame('insufficient_funds', $this->gateway->fetchPayment($session->providerPaymentId)->failureCode);
    }

    public function test_a_tampered_payload_is_rejected(): void
    {
        $session = $this->gateway->createCheckout($this->checkoutRequest('checkout-1'));
        $webhook = $this->gateway->simulate($session->providerPaymentId, PaymentStatus::Succeeded);

        $tampered = str_replace('433575', '100', $webhook['payload']);

        $this->expectException(InvalidWebhookSignature::class);

        $this->gateway->verifyWebhook($tampered, $webhook['headers']);
    }

    public function test_a_signature_made_with_another_secret_is_rejected(): void
    {
        $forger = new FakePaymentGateway(Cache::store(), 'not-the-secret', 300, ['MUR']);
        $forged = $forger->signedWebhook(['id' => 'evt_1', 'type' => 'payment.succeeded', 'data' => []]);

        $this->expectException(InvalidWebhookSignature::class);

        $this->gateway->verifyWebhook($forged['payload'], $forged['headers']);
    }

    public function test_stale_webhooks_are_rejected_to_stop_replays(): void
    {
        $stale = $this->gateway->signedWebhook(
            ['id' => 'evt_1', 'type' => 'payment.succeeded', 'data' => []],
            now()->subMinutes(10)->getTimestamp(),
        );

        $this->expectException(InvalidWebhookSignature::class);

        $this->gateway->verifyWebhook($stale['payload'], $stale['headers']);
    }

    public function test_missing_signatures_are_rejected(): void
    {
        $this->expectException(InvalidWebhookSignature::class);

        $this->gateway->verifyWebhook('{"id":"evt_1","type":"payment.succeeded"}', []);
    }

    public function test_verification_fails_closed_without_a_secret(): void
    {
        $unconfigured = new FakePaymentGateway(Cache::store(), null, 300, ['MUR']);

        $this->expectException(InvalidWebhookSignature::class);

        $unconfigured->verifyWebhook('{}', [FakePaymentGateway::SIGNATURE_HEADER => 't=1,v1='.str_repeat('a', 64)]);
    }

    public function test_refunds_are_capped_idempotent_and_only_for_captured_payments(): void
    {
        $session = $this->gateway->createCheckout($this->checkoutRequest('checkout-1'));

        try {
            $this->gateway->refund(new RefundRequest($session->providerPaymentId, Money::of('100', 'MUR'), 'refund-0'));
            $this->fail('Refunded an uncaptured payment.');
        } catch (PaymentProviderException) {
            // expected
        }

        $this->gateway->simulate($session->providerPaymentId, PaymentStatus::Succeeded);

        $first = $this->gateway->refund(new RefundRequest($session->providerPaymentId, Money::of('850', 'MUR'), 'refund-1'));
        $repeat = $this->gateway->refund(new RefundRequest($session->providerPaymentId, Money::of('850', 'MUR'), 'refund-1'));

        $this->assertSame(RefundStatus::Succeeded, $first->status);
        $this->assertSame($first->providerRefundId, $repeat->providerRefundId);

        $payment = $this->gateway->fetchPayment($session->providerPaymentId);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertTrue($payment->amountRefunded->equals(Money::of('850', 'MUR')), 'a repeated request must not refund twice');

        $this->expectException(PaymentProviderException::class);

        $this->gateway->refund(new RefundRequest($session->providerPaymentId, Money::of('4000', 'MUR'), 'refund-2'));
    }

    public function test_the_fake_provider_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(PaymentProviderException::class);

        (new PaymentGatewayManager($this->app, $this->app['config'], $this->app['cache']))->driver('fake');
    }

    public function test_unknown_providers_are_refused(): void
    {
        $this->expectException(PaymentProviderException::class);

        app(PaymentGatewayManager::class)->driver('acme-pay');
    }

    private function checkoutRequest(string $key): CheckoutRequest
    {
        return new CheckoutRequest(
            idempotencyKey: $key,
            amount: Money::of('4335.75', 'MUR'),
            orderReference: 'TKR-7Q4M-2KD9',
            customerEmail: 'amara@example.test',
            returnUrl: 'https://tickora.test/checkout/return',
            cancelUrl: 'https://tickora.test/checkout/cancel',
        );
    }
}
