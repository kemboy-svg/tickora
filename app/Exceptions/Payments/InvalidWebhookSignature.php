<?php

namespace App\Exceptions\Payments;

use RuntimeException;

class InvalidWebhookSignature extends RuntimeException
{
    public static function missing(): self
    {
        return new self('Webhook signature header is missing or malformed.');
    }

    public static function mismatch(): self
    {
        return new self('Webhook signature does not match the payload.');
    }

    public static function stale(): self
    {
        return new self('Webhook timestamp is outside the allowed tolerance.');
    }

    public static function notConfigured(string $provider): self
    {
        return new self("No webhook secret is configured for the [{$provider}] payment provider.");
    }
}
