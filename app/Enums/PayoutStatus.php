<?php

namespace App\Enums;

enum PayoutStatus: string
{
    /** Calculated and expected; amount may still change before processing. */
    case Scheduled = 'scheduled';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Shown to organisers as an estimate rather than a completed transfer.
     */
    public function isEstimate(): bool
    {
        return $this === self::Scheduled || $this === self::Processing;
    }
}
