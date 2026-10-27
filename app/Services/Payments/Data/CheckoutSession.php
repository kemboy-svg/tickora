<?php

namespace App\Services\Payments\Data;

use App\Enums\PaymentStatus;

final readonly class CheckoutSession
{
    public function __construct(
        public string $providerPaymentId,
        public string $redirectUrl,
        public PaymentStatus $status,
        public string $rawStatus,
    ) {}
}
