<?php

namespace App\Exceptions;

use InvalidArgumentException;

class UnsupportedCurrencyException extends InvalidArgumentException
{
    public static function unknown(string $code): self
    {
        return new self("Currency [{$code}] is not a currency Tickora can handle.");
    }

    public static function notAllowed(string $code): self
    {
        return new self("Currency [{$code}] is not enabled for events on this platform.");
    }
}
