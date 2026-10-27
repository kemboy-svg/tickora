<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Released = 'released';
    case Expired = 'expired';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Converted, self::Released, self::Expired],
            self::Converted, self::Released, self::Expired => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
