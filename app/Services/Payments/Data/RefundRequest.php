<?php

namespace App\Services\Payments\Data;

use App\Support\Money;

final readonly class RefundRequest
{
    public function __construct(
        public string $providerPaymentId,
        public Money $amount,
        public string $idempotencyKey,
        public ?string $reason = null,
    ) {}
}
