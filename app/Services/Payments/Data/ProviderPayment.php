<?php

namespace App\Services\Payments\Data;

use App\Enums\PaymentStatus;
use App\Support\Money;

/**
 * The provider's own view of a payment, as re-read by fetchPayment().
 */
final readonly class ProviderPayment
{
    public function __construct(
        public string $providerPaymentId,
        public PaymentStatus $status,
        public string $rawStatus,
        public Money $amount,
        public Money $amountRefunded,
        public ?string $failureCode = null,
    ) {}
}
