<?php

namespace App\Support;

/**
 * Human-friendly, unguessable references shown to customers and support,
 * e.g. "TKR-7Q4M-2KD9". Uses Crockford Base32 without I, L, O and U so
 * references survive being read aloud or typed from a printout.
 *
 * References are display identifiers, not secrets: authorisation never
 * depends on someone not knowing one.
 */
final class Reference
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * Two groups of four characters: 32^8 ≈ 1.1 × 10^12 combinations.
     * Callers persist under a unique index and retry on collision.
     */
    public static function order(): string
    {
        return 'TKR-'.self::group(4).'-'.self::group(4);
    }

    /**
     * A ticket's reference extends its order's: "TKR-7Q4M-2KD9-01".
     */
    public static function ticket(string $orderReference, int $sequence): string
    {
        return $orderReference.'-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    public static function payout(): string
    {
        return 'PO-'.self::group(4).'-'.self::group(4);
    }

    private static function group(int $length): string
    {
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }
}
