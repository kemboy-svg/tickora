<?php

namespace App\Services\Payments\Paystack;

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
 * Paystack via its hosted payment page (transaction/initialize).
 *
 * The provider payment id is the transaction reference, which Tickora
 * derives from the payment attempt's idempotency key, so retrying an
 * attempt can never create a second transaction. Webhooks are signed with
 * HMAC-SHA512 of the raw body using the secret key ("x-paystack-signature").
 *
 * Amounts are in the currency's subunit (kobo, pesewas, cents), which is
 * Tickora's minor unit.
 */
final class PaystackPaymentGateway implements PaymentGateway
{
    use ReadsProviderJson;

    public const API = 'https://api.paystack.co';

    public const SIGNATURE_HEADER = 'x-paystack-signature';

    /**
     * @param  list<string>  $currencies
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $secretKey,
        private readonly array $currencies,
    ) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function verifyCredentials(): void
    {
        $this->send(fn (PendingRequest $r) => $r->get('/balance'));
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->currencies, true);
    }

    /**
     * Paystack references allow letters, digits, "-", "." and "=".
     */
    public static function referenceFor(string $idempotencyKey): string
    {
        return 'TKR-'.substr(hash('sha256', $idempotencyKey), 0, 32);
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        if (! $this->supportsCurrency($request->amount->currency)) {
            throw PaymentProviderException::unsupportedCurrency($this->name(), $request->amount->currency);
        }

        $reference = self::referenceFor($request->idempotencyKey);

        $data = self::arr($this->send(fn (PendingRequest $r) => $r->asJson()->post('/transaction/initialize', [
            'email' => $request->customerEmail,
            'amount' => $request->amount->amount,
            'currency' => $request->amount->currency,
            'reference' => $reference,
            'callback_url' => $request->returnUrl,
            'metadata' => [
                'order_reference' => $request->orderReference,
                'cancel_action' => $request->cancelUrl,
                ...$request->metadata,
            ],
        ])), 'data');

        $url = self::str($data, 'authorization_url');

        if ($url === null) {
            throw new PaymentProviderException('Paystack did not return a payment page.');
        }

        return new CheckoutSession(
            providerPaymentId: self::str($data, 'reference') ?? $reference,
            redirectUrl: $url,
            status: PaymentStatus::Created,
            rawStatus: 'initialized',
        );
    }

    public function verifyWebhook(string $payload, array $headers): WebhookEvent
    {
        $signature = self::header($headers, self::SIGNATURE_HEADER);

        if ($signature === null || $signature === '') {
            throw InvalidWebhookSignature::missing();
        }

        if (! hash_equals(hash_hmac('sha512', $payload, $this->secretKey), strtolower($signature))) {
            throw InvalidWebhookSignature::mismatch();
        }

        try {
            $body = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentProviderException('Signed Paystack webhook is not valid JSON.');
        }

        if (! is_array($body)) {
            throw new PaymentProviderException('Signed Paystack webhook is not an object.');
        }

        $type = self::str($body, 'event');
        $data = self::arr($body, 'data');
        $reference = self::str($data, 'reference');

        if ($type === null) {
            throw new PaymentProviderException('Paystack webhook is missing its event type.');
        }

        // Paystack events carry no event id: the type plus the
        // transaction's own id identifies a delivery.
        $eventId = $type.':'.(self::str($data, 'id') ?? self::int($data, 'id') ?? $reference ?? sha1($payload));
        $raw = self::str($data, 'status');

        $status = match (true) {
            $type === 'charge.success' && $raw === 'success' => PaymentStatus::Succeeded,
            $type === 'charge.failed' => PaymentStatus::Failed,
            default => null,
        };

        $amount = self::int($data, 'amount');
        $currency = self::str($data, 'currency');

        /** @var array<string, mixed> $body */
        return new WebhookEvent(
            provider: $this->name(),
            providerEventId: $eventId,
            type: $type,
            providerPaymentId: str_starts_with($type, 'charge.') ? $reference : null,
            status: $status,
            rawStatus: $raw,
            amount: $amount !== null && $currency !== null ? Money::ofMinor($amount, strtoupper($currency)) : null,
            payload: $body,
        );
    }

    public function fetchPayment(string $providerPaymentId): ProviderPayment
    {
        $data = self::arr($this->send(fn (PendingRequest $r) => $r->get('/transaction/verify/'.rawurlencode($providerPaymentId))), 'data');

        $raw = self::str($data, 'status') ?? 'unknown';
        $currency = strtoupper(self::str($data, 'currency') ?? '');

        return new ProviderPayment(
            providerPaymentId: $providerPaymentId,
            status: match ($raw) {
                'success' => PaymentStatus::Succeeded,
                'failed' => PaymentStatus::Failed,
                'reversed' => PaymentStatus::Refunded,
                'ongoing', 'pending', 'processing', 'queued' => PaymentStatus::Processing,
                default => PaymentStatus::Created, // abandoned: the customer can still pay
            },
            rawStatus: $raw,
            amount: Money::ofMinor(self::int($data, 'amount') ?? 0, $currency),
            amountRefunded: Money::ofMinor(0, $currency),
            failureCode: $raw === 'failed' ? (self::str($data, 'gateway_response') ?? 'failed') : null,
        );
    }

    public function refund(RefundRequest $request): ProviderRefund
    {
        $data = self::arr($this->send(fn (PendingRequest $r) => $r->asJson()->post('/refund', array_filter([
            'transaction' => $request->providerPaymentId,
            'amount' => $request->amount->amount,
            'merchant_note' => $request->reason !== null ? mb_substr($request->reason, 0, 200) : null,
        ]))), 'data');

        $raw = self::str($data, 'status') ?? 'pending';

        return new ProviderRefund(
            providerRefundId: (string) (self::str($data, 'id') ?? self::int($data, 'id') ?? ''),
            providerPaymentId: $request->providerPaymentId,
            amount: $request->amount,
            status: match ($raw) {
                'processed' => RefundStatus::Succeeded,
                'failed' => RefundStatus::Failed,
                default => RefundStatus::Processing,
            },
            rawStatus: $raw,
        );
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
            throw new PaymentProviderException('Could not reach Paystack. Try again in a moment.');
        }

        $json = $response->json();
        $body = is_array($json) ? $json : [];

        if ($response->failed() || ($body['status'] ?? null) === false) {
            throw PaymentProviderException::requestFailed('Paystack', $response->status(), self::str($body, 'message'));
        }

        return $body;
    }
}
