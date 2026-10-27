<?php

namespace App\Services\Payments\Data;

use App\Support\Money;

final readonly class CheckoutRequest
{
    /**
     * @param  array<string, string>  $metadata  Non-sensitive references only (order public ID, event public ID).
     */
    public function __construct(
        public string $idempotencyKey,
        public Money $amount,
        public string $orderReference,
        public string $customerEmail,
        public string $returnUrl,
        public string $cancelUrl,
        public array $metadata = [],
    ) {}
}
