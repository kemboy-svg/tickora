<?php

namespace App\Exceptions;

use BackedEnum;
use LogicException;

class InvalidStateTransition extends LogicException
{
    public static function between(string $subject, BackedEnum $from, BackedEnum $to): self
    {
        return new self(sprintf(
            '%s cannot move from [%s] to [%s].',
            $subject,
            (string) $from->value,
            (string) $to->value,
        ));
    }
}
