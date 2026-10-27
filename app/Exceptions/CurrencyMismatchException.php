<?php

namespace App\Exceptions;

use InvalidArgumentException;

class CurrencyMismatchException extends InvalidArgumentException
{
    public static function between(string $a, string $b): self
    {
        return new self("Cannot combine amounts in [{$a}] and [{$b}].");
    }
}
