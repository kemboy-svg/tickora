<?php

namespace App\Exceptions\Payments;

use RuntimeException;

class PaymentProviderException extends RuntimeException
{
    public static function unknownProvider(string $name): self
    {
        return new self("Payment provider [{$name}] is not configured.");
    }

    public static function paymentNotFound(string $provider, string $id): self
    {
        return new self("Payment [{$id}] was not found at provider [{$provider}].");
    }

    public static function unsupportedCurrency(string $provider, string $currency): self
    {
        return new self("Payment provider [{$provider}] does not support [{$currency}].");
    }

    public static function notRefundable(string $reason): self
    {
        return new self("Refund rejected: {$reason}");
    }

    public static function disabledInProduction(string $provider): self
    {
        return new self("The [{$provider}] payment provider cannot be used in production.");
    }

    public static function notConfigured(string $provider): self
    {
        return new self("Payments with {$provider} are not set up yet: an admin needs to add its keys in Payment settings.");
    }

    public static function disabled(string $provider): self
    {
        return new self("Payments with {$provider} are switched off.");
    }

    /**
     * A failed API call. Only the provider's own error message is kept;
     * request details (which contain keys) never are.
     */
    public static function requestFailed(string $provider, int $status, ?string $message): self
    {
        $detail = $message !== null && $message !== '' ? ": {$message}" : '';

        return new self("{$provider} request failed (HTTP {$status}){$detail}");
    }
}
