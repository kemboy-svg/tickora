<?php

namespace App\Services\Checkout;

use App\Models\TicketType;
use App\Support\Money;

final readonly class QuoteLine
{
    public function __construct(
        public TicketType $ticketType,
        public int $quantity,
        public Money $unitPrice,
        public Money $subtotal,
        public Money $discount,
        public Money $fee,
        public Money $tax,
    ) {}

    /**
     * Ticket revenue for this line after discount.
     */
    public function net(): Money
    {
        return $this->subtotal->minus($this->discount);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ticket_type' => $this->ticketType->public_id,
            'name' => $this->ticketType->name,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
        ];
    }
}
