<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Converts between the wall-clock times organisers type (in the event's
 * timezone, as produced by <input type="datetime-local">) and the UTC
 * timestamps stored in the database.
 */
final class LocalTime
{
    public const INPUT_FORMAT = 'Y-m-d\TH:i';

    public static function toUtc(?string $local, string $timezone): ?CarbonImmutable
    {
        if ($local === null || $local === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat(self::INPUT_FORMAT, $local, $timezone);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $parsed?->utc();
    }

    /**
     * Back to the form's format, in the event's timezone.
     */
    public static function toInput(?CarbonInterface $utc, string $timezone): ?string
    {
        return $utc?->copy()->setTimezone($timezone)->format(self::INPUT_FORMAT);
    }

    /**
     * Human display in the event's timezone, e.g. "Sat 14 Nov 2026, 19:00".
     */
    public static function display(?CarbonInterface $utc, string $timezone, string $format = 'D j M Y, H:i'): ?string
    {
        return $utc?->copy()->setTimezone($timezone)->format($format);
    }

    /**
     * The zone's UTC offset at a moment, e.g. "UTC+4" or "UTC+5:30".
     */
    public static function offsetLabel(CarbonInterface $utc, string $timezone): string
    {
        $minutes = (int) $utc->copy()->setTimezone($timezone)->utcOffset();
        $sign = $minutes < 0 ? '-' : '+';
        $minutes = abs($minutes);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 'UTC'.$sign.$hours.($rest > 0 ? ':'.str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '');
    }
}
