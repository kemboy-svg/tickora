<?php

namespace App\Enums;

/**
 * Tickora's internal payment state machine. Provider statuses are mapped onto
 * these by each gateway adapter; the raw provider status is stored alongside.
 *
 *   created -> requires_action -> processing -> succeeded
 *   created/requires_action/processing -> failed
 *   succeeded -> partially_refunded -> refunded
 *
 * Webhooks can arrive out of order, so a payment may jump straight from
 * created to succeeded or failed. A failed payment never becomes succeeded:
 * a retry creates a new payment record.
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::RequiresAction, self::Processing, self::Succeeded, self::Failed],
            self::RequiresAction => [self::Processing, self::Succeeded, self::Failed],
            self::Processing => [self::RequiresAction, self::Succeeded, self::Failed],
            self::Succeeded => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Failed, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Funds have been captured (and possibly partly returned since).
     */
    public function isCaptured(): bool
    {
        return in_array($this, [self::Succeeded, self::PartiallyRefunded, self::Refunded], true);
    }
}
