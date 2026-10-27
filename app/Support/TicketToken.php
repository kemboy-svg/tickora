<?php

namespace App\Support;

/**
 * The secret carried in a ticket's QR code.
 *
 * Each ticket gets 256 bits of randomness. Only the SHA-256 hash is stored;
 * the raw token exists in the QR payload (and the ticket page/PDF that shows
 * it) and nowhere else, so a database leak does not leak usable tickets.
 *
 * The QR payload is "<version>.<token>", e.g. "TK1.x9Q…", and never contains
 * ticket IDs, emails, phone numbers or payment data.
 */
final readonly class TicketToken
{
    private function __construct(public string $raw) {}

    public static function issue(): self
    {
        return new self(self::base64Url(random_bytes(32)));
    }

    /**
     * Rebuild from a scanned QR payload; null if it is not a Tickora payload.
     */
    public static function fromPayload(string $payload): ?self
    {
        $prefix = self::version().'.';
        $payload = trim($payload);

        if (! str_starts_with($payload, $prefix)) {
            return null;
        }

        $raw = substr($payload, strlen($prefix));

        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $raw) !== 1) {
            return null;
        }

        return new self($raw);
    }

    public static function hashOf(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * What gets stored in tickets.qr_token_hash.
     */
    public function hash(): string
    {
        return self::hashOf($this->raw);
    }

    /**
     * What gets encoded into the QR image.
     */
    public function payload(): string
    {
        return self::version().'.'.$this->raw;
    }

    /**
     * Payload format version. Bump it if the token format ever changes so
     * scanners can tell old and new payloads apart.
     */
    private static function version(): string
    {
        return 'TK1';
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
