<?php

namespace App\Contracts\Payments;

use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Exceptions\Payments\PaymentProviderException;
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\Data\CheckoutSession;
use App\Services\Payments\Data\ProviderPayment;
use App\Services\Payments\Data\ProviderRefund;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\Data\WebhookEvent;

/**
 * The only way business code talks to a payment provider. Each provider is
 * an adapter behind this interface; nothing outside App\Services\Payments
 * may depend on a provider SDK or its objects.
 *
 * Rules every adapter must follow:
 *  - Hosted checkout or tokenisation only. Card numbers and CVV never touch
 *    Tickora servers.
 *  - Calls that create money movement take an idempotency key and return the
 *    same result when repeated with it.
 *  - A browser redirect is never proof of payment. Payment success is only
 *    trusted from a verified webhook, or from fetchPayment() re-reading the
 *    provider's own record.
 *  - Provider statuses are mapped onto App\Enums\PaymentStatus, and the raw
 *    provider status is returned alongside so it can be stored.
 */
interface PaymentGateway
{
    /**
     * Stable identifier stored on payments.provider, e.g. "fake", "stripe".
     */
    public function name(): string;

    /**
     * Make a harmless authenticated call to check the configured keys.
     *
     * @throws PaymentProviderException with a message safe to show admins
     */
    public function verifyCredentials(): void;

    /**
     * Whether this provider can charge in the given ISO 4217 currency.
     */
    public function supportsCurrency(string $currency): bool;

    /**
     * Start a hosted checkout. Repeating the call with the same idempotency
     * key returns the same session rather than creating a second charge.
     *
     * @throws PaymentProviderException
     */
    public function createCheckout(CheckoutRequest $request): CheckoutSession;

    /**
     * Verify a webhook against the raw request body (not re-encoded JSON)
     * and return its normalised contents.
     *
     * @param  array<string, string|list<string>>  $headers
     *
     * @throws InvalidWebhookSignature when the signature is missing, wrong or stale
     */
    public function verifyWebhook(string $payload, array $headers): WebhookEvent;

    /**
     * Re-read a payment from the provider. Used for ambiguous or high-risk
     * webhooks and for daily reconciliation.
     *
     * @throws PaymentProviderException
     */
    public function fetchPayment(string $providerPaymentId): ProviderPayment;

    /**
     * Refund all or part of a captured payment. Idempotent per key.
     *
     * @throws PaymentProviderException
     */
    public function refund(RefundRequest $request): ProviderRefund;
}
