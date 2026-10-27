<?php

namespace App\Services\Payments\Data;

use App\Enums\RefundStatus;
use App\Support\Money;

final readonly class ProviderRefund
{
    public function __construct(
        public string $providerRefundId,
        public string $providerPaymentId,
        public Money $amount,
        public RefundStatus $status,
        public string $rawStatus,
    ) {}
}
