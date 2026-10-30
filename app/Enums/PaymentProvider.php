<?php

namespace App\Enums;

/**
 * Payment providers Tickora can take money with. "fake" is the built-in
 * sandbox and only exists outside production.
 */
enum PaymentProvider: string
{
    case Stripe = 'stripe';
    case Paystack = 'paystack';
    case Fake = 'fake';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::Paystack => 'Paystack',
            self::Fake => 'Sandbox (test only)',
        };
    }

    /**
     * Providers whose keys admins manage in the app.
     *
     * @return list<self>
     */
    public static function configurable(): array
    {
        return [self::Stripe, self::Paystack];
    }

    /**
     * Providers that may be chosen in this environment.
     *
     * @return list<self>
     */
    public static function selectable(bool $production): array
    {
        return $production ? self::configurable() : [...self::configurable(), self::Fake];
    }
}
