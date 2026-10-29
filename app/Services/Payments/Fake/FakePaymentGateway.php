<?php

namespace App\Services\Payments\Fake;

use App\Contracts\Payments\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Exceptions\Payments\PaymentProviderException;
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Data\CheckoutSession;
use App\Services\Payments\Data\ProviderPayment;
use App\Services\Payments\Data\ProviderRefund;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\Data\WebhookEvent;
use App\Support\Money;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;
use JsonException;

/**
 * A sandbox provider for local development and automated tests. It behaves
 * like a real hosted-checkout provider — idempotent sessions, HMAC-signed
 * webhooks with a timestamp tolerance, refunds capped at the captured amount
 * — so checkout and webhook code is written against realistic behaviour
 * before a real provider is chosen. It refuses to run in production.
 *
 * Payments only change state when something calls simulate(), which also
 * returns the signed webhook the provider would have sent.
 *
 * Signature header: "Tickora-Fake-Signature: t=<unix>,v1=<hex hmac-sha256>"
 * computed over "<unix>.<raw body>" with the configured webhook secret.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'tickora-fake-signature';

    private const CACHE_PREFIX = 'payments:fake:';

    /**
     * @param  list<string>  $currencies
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly ?string $webhookSecret,
        private readonly int $toleranceSeconds = 300,
        private readonly array $currencies = [],
        private readonly ?string $hostedPageUrl = null,
    ) {}

    public function name(): string
    {
        return 'fake';
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

        $id = 'fake_pay_'.substr(hash('sha256', $request->idempotencyKey), 0, 24);
        $record = $this->load($id);

        if ($record === null) {
            $record = [
                'status' => 'created',
                'amount' => $request->amount->amount,
                'currency' => $request->amount->currency,
                'refunded' => 0,
                'reference' => $request->orderReference,
                'failure_code' => null,
            ];
            $this->save($id, $record);
        }

        $this->cache->put(self::CACHE_PREFIX.'urls:'.$id, [
            'return' => $request->returnUrl,
            'cancel' => $request->cancelUrl,
        ], now()->addDay());

        // With a hosted page configured (the app's sandbox screen), the
        // customer goes there to "pay", as with a real provider. Without
        // one, they go straight back to the return URL.
        $separator = str_contains($request->returnUrl, '?') ? '&' : '?';
        $redirect = $this->hostedPageUrl !== null
            ? rtrim($this->hostedPageUrl, '/').'/'.$id
            : $request->returnUrl.$separator.'fake_payment='.$id;

        return new CheckoutSession(
            providerPaymentId: $id,
            redirectUrl: $redirect,
            status: $this->mapStatus($record),
            rawStatus: $record['status'],
        );
    }

    public function verifyWebhook(string $payload, array $headers): WebhookEvent
    {
        $secret = $this->secret();
        $header = $this->header($headers, self::SIGNATURE_HEADER);

        if ($header === null || preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/', $header, $m) !== 1) {
            throw InvalidWebhookSignature::missing();
        }

        $timestamp = (int) $m[1];

        if (abs(now()->getTimestamp() - $timestamp) > $this->toleranceSeconds) {
            throw InvalidWebhookSignature::stale();
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        if (! hash_equals($expected, $m[2])) {
            throw InvalidWebhookSignature::mismatch();
        }

        try {
            $body = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentProviderException('Signed webhook body is not valid JSON.');
        }

        if (! is_array($body) || ! is_string($body['id'] ?? null) || ! is_string($body['type'] ?? null)) {
            throw new PaymentProviderException('Signed webhook body is missing its id or type.');
        }

        $eventId = $body['id'];
        $type = $body['type'];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $paymentId = is_string($data['payment_id'] ?? null) ? $data['payment_id'] : null;
        $rawStatus = is_string($data['status'] ?? null) ? $data['status'] : null;
        $amount = is_int($data['amount_minor'] ?? null) && is_string($data['currency'] ?? null)
            ? Money::ofMinor($data['amount_minor'], $data['currency'])
            : null;

        /** @var array<string, mixed> $body */
        return new WebhookEvent(
            provider: $this->name(),
            providerEventId: $eventId,
            type: $type,
            providerPaymentId: $paymentId,
            status: $rawStatus !== null ? PaymentStatus::tryFrom($rawStatus) : null,
            rawStatus: $rawStatus,
            amount: $amount,
            payload: $body,
        );
    }

    public function fetchPayment(string $providerPaymentId): ProviderPayment
    {
        $record = $this->load($providerPaymentId)
            ?? throw PaymentProviderException::paymentNotFound($this->name(), $providerPaymentId);

        return new ProviderPayment(
            providerPaymentId: $providerPaymentId,
            status: $this->mapStatus($record),
            rawStatus: $record['status'],
            amount: Money::ofMinor($record['amount'], $record['currency']),
            amountRefunded: Money::ofMinor($record['refunded'], $record['currency']),
            failureCode: $record['failure_code'],
        );
    }

    public function refund(RefundRequest $request): ProviderRefund
    {
        $refundId = 'fake_re_'.substr(hash('sha256', $request->idempotencyKey), 0, 24);
        $previous = $this->cache->get(self::CACHE_PREFIX.'refund:'.$refundId);

        if ($previous instanceof ProviderRefund) {
            return $previous;
        }

        $record = $this->load($request->providerPaymentId)
            ?? throw PaymentProviderException::paymentNotFound($this->name(), $request->providerPaymentId);

        if ($record['status'] !== 'succeeded') {
            throw PaymentProviderException::notRefundable('the payment has not been captured.');
        }

        if ($request->amount->currency !== $record['currency']) {
            throw PaymentProviderException::notRefundable('the refund currency differs from the payment currency.');
        }

        if (! $request->amount->isPositive()) {
            throw PaymentProviderException::notRefundable('the refund amount must be positive.');
        }

        if ($record['amount'] < $record['refunded'] + $request->amount->amount) {
            throw PaymentProviderException::notRefundable('the refund exceeds the amount still refundable.');
        }

        $record['refunded'] += $request->amount->amount;
        $this->save($request->providerPaymentId, $record);

        $refund = new ProviderRefund(
            providerRefundId: $refundId,
            providerPaymentId: $request->providerPaymentId,
            amount: $request->amount,
            status: RefundStatus::Succeeded,
            rawStatus: 'succeeded',
        );

        $this->cache->put(self::CACHE_PREFIX.'refund:'.$refundId, $refund, now()->addDay());

        return $refund;
    }

    /**
     * What the hosted sandbox page shows: amount, reference, state and
     * where to send the customer afterwards.
     *
     * @return array{amount: Money, reference: string, status: PaymentStatus, return_url: string, cancel_url: string}|null
     */
    public function session(string $providerPaymentId): ?array
    {
        $record = $this->load($providerPaymentId);
        $urls = $this->cache->get(self::CACHE_PREFIX.'urls:'.$providerPaymentId);

        if ($record === null || ! is_array($urls) || ! is_string($urls['return'] ?? null) || ! is_string($urls['cancel'] ?? null)) {
            return null;
        }

        return [
            'amount' => Money::ofMinor($record['amount'], $record['currency']),
            'reference' => $record['reference'],
            'status' => $this->mapStatus($record),
            'return_url' => $urls['return'],
            'cancel_url' => $urls['cancel'],
        ];
    }

    /**
     * Move a sandbox payment to a new state, as the customer completing (or
     * abandoning) the hosted page would, and return the signed webhook the
     * provider sends about it.
     *
     * @return array{payload: string, headers: array<string, string>}
     */
    public function simulate(string $providerPaymentId, PaymentStatus $status, ?string $failureCode = null): array
    {
        $record = $this->load($providerPaymentId)
            ?? throw PaymentProviderException::paymentNotFound($this->name(), $providerPaymentId);

        $record['status'] = $status->value;
        $record['failure_code'] = $status === PaymentStatus::Failed ? ($failureCode ?? 'card_declined') : null;
        $this->save($providerPaymentId, $record);

        return $this->signedWebhook([
            'id' => 'fake_evt_'.Str::lower((string) Str::ulid()),
            'type' => 'payment.'.$status->value,
            'data' => [
                'payment_id' => $providerPaymentId,
                'status' => $status->value,
                'amount_minor' => $record['amount'],
                'currency' => $record['currency'],
                'reference' => $record['reference'],
                'failure_code' => $record['failure_code'],
            ],
        ]);
    }

    /**
     * Sign an arbitrary body the way the provider does. Lets tests replay,
     * reorder or tamper with events.
     *
     * @param  array<string, mixed>  $body
     * @return array{payload: string, headers: array<string, string>}
     */
    public function signedWebhook(array $body, ?int $timestamp = null): array
    {
        $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret());

        return [
            'payload' => $payload,
            'headers' => [self::SIGNATURE_HEADER => "t={$timestamp},v1={$signature}"],
        ];
    }

    private function secret(): string
    {
        if ($this->webhookSecret === null || $this->webhookSecret === '') {
            throw InvalidWebhookSignature::notConfigured($this->name());
        }

        return $this->webhookSecret;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) !== $name) {
                continue;
            }

            $value = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($value) ? trim($value) : null;
        }

        return null;
    }

    /**
     * @param  array{status: string, amount: int, currency: string, refunded: int, reference: string, failure_code: string|null}  $record
     */
    private function mapStatus(array $record): PaymentStatus
    {
        if ($record['status'] === 'succeeded' && $record['refunded'] > 0) {
            return $record['refunded'] >= $record['amount']
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;
        }

        return PaymentStatus::tryFrom($record['status']) ?? PaymentStatus::Processing;
    }

    /**
     * @return array{status: string, amount: int, currency: string, refunded: int, reference: string, failure_code: string|null}|null
     */
    private function load(string $id): ?array
    {
        $value = $this->cache->get(self::CACHE_PREFIX.$id);

        if (! is_array($value)
            || ! is_string($value['status'] ?? null)
            || ! is_int($value['amount'] ?? null)
            || ! is_string($value['currency'] ?? null)
            || ! is_int($value['refunded'] ?? null)
            || ! is_string($value['reference'] ?? null)) {
            return null;
        }

        $failure = $value['failure_code'] ?? null;

        return [
            'status' => $value['status'],
            'amount' => $value['amount'],
            'currency' => $value['currency'],
            'refunded' => $value['refunded'],
            'reference' => $value['reference'],
            'failure_code' => is_string($failure) ? $failure : null,
        ];
    }

    /**
     * @param  array{status: string, amount: int, currency: string, refunded: int, reference: string, failure_code: string|null}  $record
     */
    private function save(string $id, array $record): void
    {
        $this->cache->put(self::CACHE_PREFIX.$id, $record, now()->addDay());
    }
}
