<?php

namespace App\Support;

use App\Exceptions\UnsupportedCurrencyException;

/**
 * ISO 4217 currency metadata Tickora knows how to handle.
 *
 * "Known" means we know the currency's minor unit and can store and format
 * amounts in it. "Allowed" additionally means the platform is configured to
 * let organisers price events in it (config/tickora.php).
 */
final class Currency
{
    /**
     * Number of decimal places in each currency's minor unit.
     *
     * @var array<string, int>
     */
    private const EXPONENTS = [
        'EUR' => 2,
        'GBP' => 2,
        'GHS' => 2,
        'KES' => 2,
        'MUR' => 2,
        'NGN' => 2,
        'RWF' => 0,
        'SCR' => 2,
        'TZS' => 2,
        'UGX' => 0,
        'USD' => 2,
        'XOF' => 0,
        'ZAR' => 2,
    ];

    public static function isKnown(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::EXPONENTS);
    }

    /**
     * Decimal places of the minor unit, e.g. 2 for MUR (cents), 0 for UGX.
     */
    public static function exponent(string $code): int
    {
        $code = self::normalise($code);

        return self::EXPONENTS[$code];
    }

    /**
     * Uppercase and validate a code; throws if Tickora cannot handle it.
     */
    public static function normalise(string $code): string
    {
        $upper = strtoupper(trim($code));

        if (! array_key_exists($upper, self::EXPONENTS)) {
            throw UnsupportedCurrencyException::unknown($code);
        }

        return $upper;
    }

    /**
     * Currencies organisers may price events in.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        /** @var array<int, string> $configured */
        $configured = config('tickora.currencies', []);

        return array_values(array_filter(
            array_map(fn (string $code): string => strtoupper($code), $configured),
            fn (string $code): bool => self::isKnown($code),
        ));
    }

    public static function isAllowed(string $code): bool
    {
        return in_array(strtoupper($code), self::allowed(), true);
    }

    public static function assertAllowed(string $code): string
    {
        $code = self::normalise($code);

        if (! self::isAllowed($code)) {
            throw UnsupportedCurrencyException::notAllowed($code);
        }

        return $code;
    }
}
