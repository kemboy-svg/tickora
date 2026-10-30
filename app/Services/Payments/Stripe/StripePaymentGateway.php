<?php

namespace App\Services\Payments\Stripe;

use App\Contracts\Payments\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Exceptions\Payments\PaymentProviderException;
use App\Services\Payments\Concerns\ReadsProviderJson;
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Data\CheckoutSession;
use App\Services\Payments\Data\ProviderPayment;
use App\Services\Payments\Data\ProviderRefund;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\Data\WebhookEvent;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;

/**
 * Stripe via hosted Checkout Sessions (card details never reach Tickora).
 *
 * The provider payment id is the Checkout Session id ("cs_…"). Webhooks
 * are verified with the endpoint's signing secret ("whsec_…") using
 * Stripe's scheme: HMAC-SHA256 over "<timestamp>.<raw body>", with a
 * timestamp tolerance against replays.
 *
 * Amounts: Stripe takes the currency's smallest unit, which is exactly
 * Tickora's minor units for the currencies we support.
 */
final class StripePaymentGateway implements PaymentGateway
{
    use ReadsProviderJson;

    public const API = 'https://api.stripe.com/v1';

    public const SIGNATURE_HEADER = 'stripe-signature';

    /**
     * @param  list<string>  $currencies
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $secretKey,
        private readonly ?string $webhookSecret,
        private readonly array $currencies,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function verifyCredentials(): void
    {
        $this->send(fn (PendingRequest $r) => $r->get('/balance'));
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->currencies, true);
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        if (! $this->supportsCurrency($request->amount->currency)) {
            throw PaymentProviderException::unsupportedCurrency($this->name(), $request->amount->currency);
        }

        $metadata = $request->metadata + ['order_reference' => $request->orderReference];

        $session = $this->send(fn (PendingRequest $r) => $r
            ->withHeaders(['Idempotency-Key' => $request->idempotencyKey])
            ->asForm()
            ->post('/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $request->returnUrl,
                'cancel_url' => $request->cancelUrl,
                'customer_email' => $request->customerEmail,
                'client_reference_id' => $request->orderReference,
                // Stripe's minimum; Tickora's own hold is shorter and wins.
                'expires_at' => now()->addMinutes(31)->getTimestamp(),
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($request->amount->currency),
                        'unit_amount' => $request->amount->amount,
                        'product_data' => ['name' => "Tickets · order {$request->orderReference}"],
                    ],
                ]],
                'metadata' => $metadata,
                'payment_intent_data' => ['metadata' => $metadata],
            ]));

        $id = self::str($session, 'id');
        $url = self::str($session, 'url');

        if ($id === null || $url === null) {
            throw new PaymentProviderException('Stripe did not return a checkout session.');
        }

        return new CheckoutSession(
            providerPaymentId: $id,
            redirectUrl: $url,
            status: $this->mapSession($session),
            rawStatus: self::str($session, 'status') ?? 'open',
        );
    }

    public function verifyWebhook(string $payload, array $headers): WebhookEvent
    {
        if ($this->webhookSecret === null || $this->webhookSecret === '') {
            throw InvalidWebhookSignature::notConfigured($this->name());
        }

        $header = self::header($headers, self::SIGNATURE_HEADER);

        if ($header === null) {
            throw InvalidWebhookSignature::missing();
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw InvalidWebhookSignature::missing();
        }

        if (abs(now()->getTimestamp() - $timestamp) > $this->toleranceSeconds) {
            throw InvalidWebhookSignature::stale();
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);
        $valid = array_filter($signatures, fn (string $sig): bool => hash_equals($expected, $sig));

        if ($valid === []) {
            throw InvalidWebhookSignature::mismatch();
        }

        try {
            $body = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentProviderException('Signed Stripe webhook is not valid JSON.');
        }

        if (! is_array($body)) {
            throw new PaymentProviderException('Signed Stripe webhook is not an object.');
        }

        $eventId = self::str($body, 'id');
        $type = self::str($body, 'type');

        if ($eventId === null || $type === null) {
            throw new PaymentProviderException('Stripe webhook is missing its id or type.');
        }

        $object = self::arr(self::arr($body, 'data'), 'object');
        $isSession = str_starts_with($type, 'checkout.session.');

        $status = match ($type) {
            'checkout.session.completed' => $this->mapSession($object),
            'checkout.session.async_payment_succeeded' => PaymentStatus::Succeeded,
            'checkout.session.async_payment_failed', 'checkout.session.expired' => PaymentStatus::Failed,
            default => null,
        };

        $amount = self::int($object, 'amount_total');
        $currency = self::str($object, 'currency');

        /** @var array<string, mixed> $body */
        return new WebhookEvent(
            provider: $this->name(),
            providerEventId: $eventId,
            type: $type,
            providerPaymentId: $isSession ? self::str($object, 'id') : null,
            status: $status,
            rawStatus: $type === 'checkout.session.expired' ? 'expired' : (self::str($object, 'payment_status') ?? $type),
            amount: $isSession && $amount !== null && $currency !== null ? Money::ofMinor($amount, strtoupper($currency)) : null,
            payload: $body,
        );
    }

    public function fetchPayment(string $providerPaymentId): ProviderPayment
    {
        $session = $this->send(fn (PendingRequest $r) => $r->get(
            '/checkout/sessions/'.rawurlencode($providerPaymentId),
            ['expand' => ['payment_intent.latest_charge']],
        ));

        $currency = strtoupper(self::str($session, 'currency') ?? '');
        $charge = self::arr(self::arr($session, 'payment_intent'), 'latest_charge');
        $status = $this->mapSession($session);
        $refunded = self::int($charge, 'amount_refunded') ?? 0;
        $amount = self::int($session, 'amount_total') ?? 0;

        if ($status === PaymentStatus::Succeeded && $refunded > 0) {
            $status = $refunded >= $amount ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
        }

        $failure = self::arr(self::arr($session, 'payment_intent'), 'last_payment_error');

        return new ProviderPayment(
            providerPaymentId: $providerPaymentId,
            status: $status,
            rawStatus: (self::str($session, 'status') ?? 'unknown').'/'.(self::str($session, 'payment_status') ?? 'unknown'),
            amount: Money::ofMinor($amount, $currency),
            amountRefunded: Money::ofMinor($refunded, $currency),
            failureCode: self::str($failure, 'code'),
        );
    }

    public function refund(RefundRequest $request): ProviderRefund
    {
        $session = $this->send(fn (PendingRequest $r) => $r->get('/checkout/sessions/'.rawurlencode($request->providerPaymentId)));
        $intent = $session['payment_intent'] ?? null;
        $intentId = is_string($intent) ? $intent : (is_array($intent) ? self::str($intent, 'id') : null);

        if ($intentId === null) {
            throw PaymentProviderException::notRefundable('the payment has not been captured.');
        }

        $refund = $this->send(fn (PendingRequest $r) => $r
            ->withHeaders(['Idempotency-Key' => $request->idempotencyKey])
            ->asForm()
            ->post('/refunds', array_filter([
                'payment_intent' => $intentId,
                'amount' => $request->amount->amount,
                'metadata' => $request->reason !== null ? ['reason' => mb_substr($request->reason, 0, 400)] : null,
            ])));

        $raw = self::str($refund, 'status') ?? 'pending';

        return new ProviderRefund(
            providerRefundId: self::str($refund, 'id') ?? '',
            providerPaymentId: $request->providerPaymentId,
            amount: $request->amount,
            status: match ($raw) {
                'succeeded' => RefundStatus::Succeeded,
                'failed', 'canceled' => RefundStatus::Failed,
                default => RefundStatus::Processing,
            },
            rawStatus: $raw,
        );
    }

    /**
     * @param  array<mixed>  $session
     */
    private function mapSession(array $session): PaymentStatus
    {
        $status = self::str($session, 'status');
        $paid = self::str($session, 'payment_status');

        return match (true) {
            $status === 'complete' && in_array($paid, ['paid', 'no_payment_required'], true) => PaymentStatus::Succeeded,
            $status === 'complete' => PaymentStatus::Processing, // e.g. bank transfers settling later
            $status === 'expired' => PaymentStatus::Failed,
            default => PaymentStatus::Created,
        };
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<mixed>
     */
    private function send(callable $call): array
    {
        try {
            $response = $call($this->http->baseUrl(self::API)->withToken($this->secretKey)->acceptJson()->timeout(20));
        } catch (ConnectionException) {
            throw new PaymentProviderException('Could not reach Stripe. Try again in a moment.');
        }

        $json = $response->json();
        $body = is_array($json) ? $json : [];

        if ($response->failed()) {
            throw PaymentProviderException::requestFailed('Stripe', $response->status(), self::str(self::arr($body, 'error'), 'message'));
        }

        return $body;
    }
}
