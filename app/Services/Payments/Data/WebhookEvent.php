<?php

namespace App\Services\Payments\Data;

use App\Enums\PaymentStatus;
use App\Support\Money;

/**
 * A verified, normalised webhook. Fields a provider event does not carry are
 * null; consumers must re-fetch the payment when they need certainty.
 */
final readonly class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload  The decoded body, kept for the audit trail.
     */
    public function __construct(
        public string $provider,
        public string $providerEventId,
        public string $type,
        public ?string $providerPaymentId,
        public ?PaymentStatus $status,
        public ?string $rawStatus,
        public ?Money $amount,
        public array $payload,
    ) {}

    /**
     * Whether this event claims a payment changed state.
     */
    public function isPaymentUpdate(): bool
    {
        return $this->providerPaymentId !== null && $this->status !== null;
    }
}
