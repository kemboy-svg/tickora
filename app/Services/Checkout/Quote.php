<?php

namespace App\Services\Checkout;

use App\Models\PromoCode;
use App\Support\Money;

/**
 * A fully priced basket. Every amount is computed on the server from the
 * ticket types' current prices; nothing here comes from the client.
 *
 * subtotal − discount = ticket revenue for the organiser
 * + booking fee + tax on the fee = total the customer pays
 */
final readonly class Quote
{
    /**
     * @param  list<QuoteLine>  $lines
     */
    public function __construct(
        public string $currency,
        public array $lines,
        public Money $subtotal,
        public Money $discount,
        public Money $fee,
        public Money $tax,
        public Money $total,
        public ?PromoCode $promoCode,
    ) {}

    public function quantity(): int
    {
        return array_sum(array_map(fn (QuoteLine $line): int => $line->quantity, $this->lines));
    }

    public function isFree(): bool
    {
        return $this->total->isZero();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'lines' => array_map(fn (QuoteLine $line): array => $line->toArray(), $this->lines),
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'fee' => $this->fee,
            'tax' => $this->tax,
            'total' => $this->total,
            'promo_code' => $this->promoCode?->code,
            'quantity' => $this->quantity(),
        ];
    }
}
