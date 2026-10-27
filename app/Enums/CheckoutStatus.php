<?php

namespace App\Enums;

enum CheckoutStatus: string
{
    case Open = 'open';
    case AwaitingPayment = 'awaiting_payment';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * Whether the checkout still holds inventory.
     */
    public function holdsInventory(): bool
    {
        return $this === self::Open || $this === self::AwaitingPayment;
    }
}
