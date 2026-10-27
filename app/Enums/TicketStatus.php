<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Valid = 'valid';
    case CheckedIn = 'checked_in';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Void = 'void';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Valid => [self::CheckedIn, self::Cancelled, self::Refunded, self::Void],
            // Undoing a check-in is an audited admin correction only.
            self::CheckedIn => [self::Valid, self::Refunded, self::Void],
            self::Cancelled, self::Refunded, self::Void => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Whether a scan of this ticket may admit someone right now.
     */
    public function isAdmissible(): bool
    {
        return $this === self::Valid;
    }
}
