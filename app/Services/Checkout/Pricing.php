<?php

namespace App\Services\Checkout;

use App\Models\PromoCode;
use App\Models\TicketType;
use App\Support\Money;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * Prices a basket. Discounts, fees and tax are calculated once at order
 * level in integer minor units, then allocated across lines without
 * losing a cent, so line amounts always add up exactly to the totals.
 */
class Pricing
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param  list<array{type: TicketType, quantity: int}>  $items
     */
    public function quote(string $currency, array $items, ?PromoCode $promo = null): Quote
    {
        if ($items === []) {
            throw new InvalidArgumentException('Cannot price an empty basket.');
        }

        $subtotals = [];

        foreach ($items as $i => $item) {
            if ($item['type']->currency !== $currency) {
                throw new InvalidArgumentException('Every ticket in an order must be in the order currency.');
            }

            $subtotals[$i] = $item['type']->price->times($item['quantity']);
        }

        $discounts = $this->discounts($currency, $items, $subtotals, $promo);

        $nets = [];
        foreach ($subtotals as $i => $subtotal) {
            $nets[$i] = $subtotal->minus($discounts[$i]);
        }

        $netTotal = $this->sum($currency, $nets);
        $feeTotal = $netTotal->percentageBps($this->feeBps());
        $taxTotal = $feeTotal->percentageBps($this->taxBps());

        $fees = $this->spread($feeTotal, $nets);
        $taxes = $this->spread($taxTotal, $fees);

        $lines = [];
        foreach ($items as $i => $item) {
            $lines[] = new QuoteLine(
                ticketType: $item['type'],
                quantity: $item['quantity'],
                unitPrice: $item['type']->price,
                subtotal: $subtotals[$i],
                discount: $discounts[$i],
                fee: $fees[$i],
                tax: $taxes[$i],
            );
        }

        $subtotal = $this->sum($currency, $subtotals);
        $discount = $this->sum($currency, $discounts);

        return new Quote(
            currency: $currency,
            lines: $lines,
            subtotal: $subtotal,
            discount: $discount,
            fee: $feeTotal,
            tax: $taxTotal,
            total: $netTotal->plus($feeTotal)->plus($taxTotal),
            promoCode: $discount->isZero() ? null : $promo,
        );
    }

    /**
     * The promo discount, spread over the lines it applies to.
     *
     * @param  list<array{type: TicketType, quantity: int}>  $items
     * @param  array<int, Money>  $subtotals
     * @return array<int, Money>
     */
    private function discounts(string $currency, array $items, array $subtotals, ?PromoCode $promo): array
    {
        $zero = array_map(fn (): Money => Money::zero($currency), $subtotals);

        if ($promo === null) {
            return $zero;
        }

        $restrictedTo = $promo->ticketTypes->pluck('id')->all();
        $eligible = [];

        foreach ($items as $i => $item) {
            if ($restrictedTo === [] || in_array($item['type']->id, $restrictedTo, true)) {
                $eligible[$i] = $subtotals[$i];
            }
        }

        $eligibleTotal = $this->sum($currency, $eligible);

        if ($eligibleTotal->isZero()) {
            return $zero;
        }

        $spread = $this->spread($promo->discountFor($eligibleTotal), $eligible);

        return array_replace($zero, $spread);
    }

    /**
     * Split an amount across keys in proportion to their weights.
     *
     * @param  array<int, Money>  $weights
     * @return array<int, Money>
     */
    private function spread(Money $amount, array $weights): array
    {
        $keys = array_keys($weights);
        $ratios = array_values(array_map(fn (Money $w): int => max(0, $w->amount), $weights));

        if ($amount->isZero() || array_sum($ratios) === 0) {
            return array_combine($keys, array_map(fn (): Money => Money::zero($amount->currency), $keys));
        }

        return array_combine($keys, $amount->allocate($ratios));
    }

    /**
     * @param  array<int, Money>  $amounts
     */
    private function sum(string $currency, array $amounts): Money
    {
        return array_reduce($amounts, fn (Money $carry, Money $m): Money => $carry->plus($m), Money::zero($currency));
    }

    private function feeBps(): int
    {
        $value = $this->config->get('tickora.platform_fee_bps', 500);

        return is_numeric($value) ? (int) $value : 500;
    }

    private function taxBps(): int
    {
        $value = $this->config->get('tickora.booking_fee_tax_bps', 0);

        return is_numeric($value) ? (int) $value : 0;
    }
}
