<?php

namespace Tests\Concerns;

use App\Enums\PaymentProvider;
use App\Models\PaymentGatewaySetting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stand-ins for Stripe's and Paystack's HTTP APIs, so adapter and checkout
 * tests run without network access or real accounts.
 */
trait FakesPaymentProviders
{
    protected const STRIPE_SECRET = 'sk_test_51TickoraTestKeyAbcdefgh1234';

    protected const STRIPE_WEBHOOK_SECRET = 'whsec_tickoratestsigningsecret';

    protected const PAYSTACK_SECRET = 'sk_test_paystackTestKey9876';

    /** Stripe session state returned by GET /checkout/sessions/{id}. */
    protected string $stripeSessionStatus = 'open';

    protected string $stripePaymentStatus = 'unpaid';

    protected int $stripeAmount = 0;

    /** Paystack transaction state returned by GET /transaction/verify. */
    protected string $paystackStatus = 'abandoned';

    protected int $paystackAmount = 0;

    protected string $paystackCurrency = 'KES';

    /** Simulate the providers being unreachable / erroring. */
    protected bool $providersDown = false;

    protected function configureProvider(PaymentProvider $provider, bool $enabled = true, bool $default = false): PaymentGatewaySetting
    {
        $setting = PaymentGatewaySetting::for($provider) ?? new PaymentGatewaySetting;
        $setting->provider = $provider;
        $setting->enabled = $enabled;
        $setting->is_default = $default;
        $setting->secret_key = $provider === PaymentProvider::Stripe ? self::STRIPE_SECRET : self::PAYSTACK_SECRET;
        $setting->webhook_secret = $provider === PaymentProvider::Stripe ? self::STRIPE_WEBHOOK_SECRET : null;
        $setting->save();

        return $setting;
    }

    protected function fakeProviderApis(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();

            if ($this->providersDown) {
                return Http::response(['error' => ['message' => 'Service unavailable'], 'status' => false, 'message' => 'Service unavailable'], 503);
            }

            if (str_starts_with($url, 'https://api.stripe.com/v1/checkout/sessions/') && $method === 'GET') {
                return Http::response([
                    'id' => 'cs_test_123',
                    'object' => 'checkout.session',
                    'status' => $this->stripeSessionStatus,
                    'payment_status' => $this->stripePaymentStatus,
                    'amount_total' => $this->stripeAmount,
                    'currency' => 'mur',
                    'payment_intent' => str_contains($url, 'expand')
                        ? ['id' => 'pi_123', 'latest_charge' => ['amount_refunded' => 0]]
                        : 'pi_123',
                ]);
            }

            if ($url === 'https://api.stripe.com/v1/checkout/sessions' && $method === 'POST') {
                parse_str($request->body(), $form);
                $this->stripeAmount = (int) ($form['line_items'][0]['price_data']['unit_amount'] ?? 0);

                return Http::response([
                    'id' => 'cs_test_123',
                    'object' => 'checkout.session',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
                    'status' => 'open',
                    'payment_status' => 'unpaid',
                    'amount_total' => $this->stripeAmount,
                    'currency' => 'mur',
                ]);
            }

            if ($url === 'https://api.stripe.com/v1/refunds') {
                return Http::response(['id' => 're_123', 'status' => 'succeeded']);
            }

            if ($url === 'https://api.stripe.com/v1/balance') {
                return $request->hasHeader('Authorization', 'Bearer '.self::STRIPE_SECRET)
                    ? Http::response(['object' => 'balance'])
                    : Http::response(['error' => ['message' => 'Invalid API Key provided: sk_test_****wxyz']], 401);
            }

            if ($url === 'https://api.paystack.co/transaction/initialize') {
                $body = $request->data();
                $this->paystackAmount = (int) ($body['amount'] ?? 0);
                $this->paystackCurrency = (string) ($body['currency'] ?? 'KES');

                return Http::response([
                    'status' => true,
                    'message' => 'Authorization URL created',
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/abc123',
                        'access_code' => 'abc123',
                        'reference' => $body['reference'] ?? 'missing',
                    ],
                ]);
            }

            if (str_starts_with($url, 'https://api.paystack.co/transaction/verify/')) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'id' => 4099260516,
                        'status' => $this->paystackStatus,
                        'reference' => basename($url),
                        'amount' => $this->paystackAmount,
                        'currency' => $this->paystackCurrency,
                    ],
                ]);
            }

            if ($url === 'https://api.paystack.co/refund') {
                return Http::response(['status' => true, 'data' => ['id' => 3018284, 'status' => 'processed']]);
            }

            if ($url === 'https://api.paystack.co/balance') {
                return Http::response(['status' => true, 'data' => []]);
            }

            return Http::response(['error' => ['message' => "Unexpected call to {$method} {$url}"]], 500);
        });
    }

    /**
     * A Stripe webhook signed the way Stripe signs them.
     *
     * @param  array<string, mixed>  $object
     * @return array{payload: string, server: array<string, string>}
     */
    protected function stripeWebhook(string $type, array $object, ?int $timestamp = null, string $secret = self::STRIPE_WEBHOOK_SECRET): array
    {
        $payload = json_encode([
            'id' => 'evt_'.bin2hex(random_bytes(6)),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);

        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return [
            'payload' => $payload,
            'server' => ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
        ];
    }

    /**
     * A Paystack webhook signed the way Paystack signs them.
     *
     * @param  array<string, mixed>  $data
     * @return array{payload: string, server: array<string, string>}
     */
    protected function paystackWebhook(string $event, array $data, string $secret = self::PAYSTACK_SECRET): array
    {
        $payload = json_encode(['event' => $event, 'data' => $data], JSON_THROW_ON_ERROR);

        return [
            'payload' => $payload,
            'server' => ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, $secret)],
        ];
    }
}
