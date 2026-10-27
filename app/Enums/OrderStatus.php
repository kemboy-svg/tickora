<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
    case PaymentFailed = 'payment_failed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::PaymentFailed, self::Cancelled],
            // A customer may retry after a failure while the checkout is open.
            self::PaymentFailed => [self::Paid, self::Cancelled],
            self::Paid => [self::PartiallyRefunded, self::Refunded],
            // Further partial refunds keep the order partially refunded.
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Refunded, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Money was captured for this order at some point.
     */
    public function wasPaid(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded, self::Refunded], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Pending payment',
            self::Paid => 'Paid',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
            self::PaymentFailed => 'Payment failed',
        };
    }
}
