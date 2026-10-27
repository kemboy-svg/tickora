<?php

namespace App\Enums;

/**
 * Outcome of one scan, recorded on every check_ins row (including refusals)
 * and returned to the scanner, which maps each case to a distinct UI state.
 */
enum CheckInResult: string
{
    case Admitted = 'admitted';
    case AlreadyCheckedIn = 'already_checked_in';
    case Invalid = 'invalid';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
    case WrongEvent = 'wrong_event';
    case EventNotActive = 'event_not_active';

    public function admits(): bool
    {
        return $this === self::Admitted;
    }
}
