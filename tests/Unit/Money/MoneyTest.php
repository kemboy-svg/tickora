<?php

namespace Tests\Unit\Money;

use App\Exceptions\CurrencyMismatchException;
use App\Exceptions\UnsupportedCurrencyException;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_parses_decimal_strings_into_minor_units(): void
    {
        $this->assertSame(433575, Money::of('4335.75', 'MUR')->amount);
        $this->assertSame(433570, Money::of('4335.7', 'MUR')->amount);
        $this->assertSame(433500, Money::of('4335', 'MUR')->amount);
        $this->assertSame(433500, Money::of(4335, 'MUR')->amount);
        $this->assertSame(-5, Money::of('-0.05', 'MUR')->amount);
        $this->assertSame(0, Money::of('-0', 'MUR')->amount);
    }

    public function test_currency_codes_are_normalised(): void
    {
        $this->assertSame('KES', Money::of('10', 'kes')->currency);
    }

    public function test_zero_decimal_currencies_have_no_minor_unit(): void
    {
        $money = Money::of('2500', 'UGX');

        $this->assertSame(2500, $money->amount);
        $this->assertSame('2500', $money->toDecimal());
        $this->assertSame('UGX 2,500', $money->format());
    }

    public function test_it_rejects_more_decimals_than_the_currency_allows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('1.005', 'MUR');
    }

    public function test_it_rejects_malformed_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('1,000.00', 'MUR');
    }

    public function test_it_rejects_unknown_currencies(): void
    {
        $this->expectException(UnsupportedCurrencyException::class);

        Money::of('1', 'XYZ');
    }

    public function test_arithmetic_is_exact(): void
    {
        $regular = Money::of('850', 'MUR');
        $vip = Money::of('2400', 'MUR');

        $subtotal = $regular->times(2)->plus($vip);

        $this->assertTrue($subtotal->equals(Money::of('4100', 'MUR')));
        $this->assertTrue($subtotal->minus($vip)->equals(Money::of('1700', 'MUR')));
        $this->assertTrue($vip->negate()->isNegative());
    }

    public function test_combining_currencies_is_refused(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1', 'MUR')->plus(Money::of('1', 'KES'));
    }

    public function test_comparisons_across_currencies_are_refused(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1', 'MUR')->greaterThan(Money::of('1', 'KES'));
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function percentageCases(): array
    {
        return [
            '5% booking fee on MUR 4,100.00' => [410000, 500, 20500],
            '15% VAT on MUR 205.00' => [20500, 1500, 3075],
            'half a cent rounds up' => [205, 1500, 31],
            'just under half rounds down' => [3, 1500, 0],
            'zero rate' => [999, 0, 0],
            'negative amounts mirror positive ones' => [-205, 1500, -31],
        ];
    }

    #[DataProvider('percentageCases')]
    public function test_percentages_round_half_away_from_zero(int $amount, int $bps, int $expected): void
    {
        $this->assertSame($expected, Money::ofMinor($amount, 'MUR')->percentageBps($bps)->amount);
    }

    public function test_negative_percentages_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ofMinor(100, 'MUR')->percentageBps(-1);
    }

    public function test_allocation_never_loses_or_creates_a_minor_unit(): void
    {
        $parts = Money::ofMinor(100, 'MUR')->allocate([1, 1, 1]);

        $this->assertSame([34, 33, 33], array_map(fn (Money $m): int => $m->amount, $parts));

        $parts = Money::ofMinor(1001, 'MUR')->allocate([850, 2400, 0]);
        $amounts = array_map(fn (Money $m): int => $m->amount, $parts);

        $this->assertSame(1001, array_sum($amounts));
        $this->assertSame(0, $amounts[2]);
    }

    public function test_allocating_negative_amounts_keeps_the_sign(): void
    {
        $parts = Money::ofMinor(-100, 'MUR')->allocate([1, 2]);

        $this->assertSame([-33, -67], array_map(fn (Money $m): int => $m->amount, $parts));
    }

    public function test_allocation_requires_a_positive_ratio(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ofMinor(100, 'MUR')->allocate([0, 0]);
    }

    public function test_formatting_uses_the_iso_code_and_grouping(): void
    {
        $this->assertSame('MUR 4,335.75', Money::of('4335.75', 'MUR')->format());
        $this->assertSame('MUR 1,234,567.89', Money::ofMinor(123456789, 'MUR')->format());
        $this->assertSame('MUR -0.05', Money::ofMinor(-5, 'MUR')->format());
        $this->assertSame('0.05', Money::ofMinor(5, 'MUR')->toDecimal());
    }

    public function test_json_includes_minor_units_and_currency(): void
    {
        $this->assertSame(
            '{"amount_minor":1000,"currency":"KES","decimal":"10.00","formatted":"KES 10.00"}',
            json_encode(Money::of('10', 'KES')),
        );
    }
}
