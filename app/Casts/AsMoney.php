<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Reads and writes a Money value from an integer minor-units column and a
 * currency column on the same row.
 *
 *   'total' => AsMoney::class.':total_minor,currency'
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
class AsMoney implements CastsAttributes
{
    public function __construct(
        private readonly string $amountColumn,
        private readonly string $currencyColumn = 'currency',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $amount = $attributes[$this->amountColumn] ?? null;
        $currency = $attributes[$this->currencyColumn] ?? null;

        if ($amount === null || ! is_string($currency) || $currency === '') {
            return null;
        }

        return Money::ofMinor((int) $amount, $currency);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$this->amountColumn => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("The [{$key}] attribute must be a Money instance.");
        }

        $existing = $attributes[$this->currencyColumn] ?? null;

        if (is_string($existing) && $existing !== '' && strtoupper($existing) !== $value->currency) {
            throw new InvalidArgumentException(
                "Cannot store a {$value->currency} amount in [{$key}] on a {$existing} record.",
            );
        }

        return [
            $this->amountColumn => $value->amount,
            $this->currencyColumn => $value->currency,
        ];
    }
}
