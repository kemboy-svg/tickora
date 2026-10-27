<?php

namespace App\Support;

use App\Exceptions\CurrencyMismatchException;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An immutable amount of money in integer minor units (e.g. cents) plus an
 * ISO 4217 currency code. Floating point is never used for amounts.
 *
 * Rounding policy: wherever a calculation produces a fraction of a minor
 * unit (percentages, fees), it rounds half away from zero — 0.5 cents rounds
 * up for positive amounts and down for negative ones, so a refund of a fee
 * exactly mirrors the fee.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    public string $currency;

    private function __construct(public int $amount, string $currency)
    {
        $this->currency = Currency::normalise($currency);
    }

    /**
     * From an integer amount of minor units: Money::ofMinor(433575, 'MUR').
     */
    public static function ofMinor(int $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    /**
     * From a decimal string in major units: Money::of('4335.75', 'MUR').
     * Rejects more decimal places than the currency's minor unit allows,
     * rather than silently rounding user input.
     */
    public static function of(string|int $amount, string $currency): self
    {
        $currency = Currency::normalise($currency);
        $exponent = Currency::exponent($currency);
        $value = trim((string) $amount);

        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException("[{$value}] is not a valid money amount.");
        }

        $negative = $m[1] === '-';
        $whole = $m[2];
        $fraction = $m[3] ?? '';

        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException(
                "[{$value}] has more decimal places than {$currency} allows ({$exponent}).",
            );
        }

        $digits = ltrim($whole.str_pad($fraction, $exponent, '0'), '0');
        $minor = $digits === '' ? 0 : (int) $digits;

        return new self($negative ? -$minor : $minor, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * Multiply by a whole quantity (e.g. unit price × tickets).
     */
    public function times(int $quantity): self
    {
        return new self($this->amount * $quantity, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    /**
     * A share expressed in basis points (1 bp = 0.01%), rounded half away
     * from zero to the nearest minor unit. 500 bps of MUR 41.00 = MUR 2.05.
     */
    public function percentageBps(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('Basis points cannot be negative.');
        }

        $product = abs($this->amount) * $basisPoints;
        $rounded = intdiv($product + 5000, 10000);

        return new self($this->amount < 0 ? -$rounded : $rounded, $this->currency);
    }

    /**
     * Split into parts proportional to $ratios without losing or creating a
     * single minor unit; leftover units go to the largest remainders first.
     * Used for spreading discounts, fees and partial refunds across lines.
     *
     * @param  list<int>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('Cannot allocate to zero parts.');
        }

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new InvalidArgumentException('Allocation ratios cannot be negative.');
            }
        }

        $total = array_sum($ratios);

        if ($total === 0) {
            throw new InvalidArgumentException('Allocation ratios must not all be zero.');
        }

        $sign = $this->amount < 0 ? -1 : 1;
        $amount = abs($this->amount);
        $shares = [];
        $remainders = [];
        $allocated = 0;

        foreach ($ratios as $i => $ratio) {
            $share = intdiv($amount * $ratio, $total);
            $shares[$i] = $share;
            $remainders[$i] = ($amount * $ratio) % $total;
            $allocated += $share;
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $i) {
            if ($allocated >= $amount) {
                break;
            }

            $shares[$i]++;
            $allocated++;
        }

        ksort($shares);

        return array_values(array_map(
            fn (int $share): self => new self($sign * $share, $this->currency),
            $shares,
        ));
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    /**
     * Exact decimal representation in major units, e.g. "4335.75" or "-0.05".
     */
    public function toDecimal(): string
    {
        $exponent = Currency::exponent($this->currency);
        $digits = (string) abs($this->amount);

        if ($exponent === 0) {
            return ($this->amount < 0 ? '-' : '').$digits;
        }

        $digits = str_pad($digits, $exponent + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$exponent);
        $fraction = substr($digits, -$exponent);

        return ($this->amount < 0 ? '-' : '').$whole.'.'.$fraction;
    }

    /**
     * Human display with an unambiguous ISO code: "MUR 4,335.75".
     * Locale-aware formatting belongs in the frontend; this is the
     * server-side fallback used in emails, PDFs and logs.
     */
    public function format(): string
    {
        $decimal = $this->toDecimal();
        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '-');

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, null);
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return $this->currency.' '.($negative ? '-' : '').$grouped.($fraction !== null ? '.'.$fraction : '');
    }

    /**
     * @return array{amount_minor: int, currency: string, decimal: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount_minor' => $this->amount,
            'currency' => $this->currency,
            'decimal' => $this->toDecimal(),
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatchException::between($this->currency, $other->currency);
        }
    }
}
