<?php

namespace Tests\Feature\Checkout;

use App\Models\PromoCode;
use App\Models\TicketType;
use App\Services\Checkout\Pricing;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use Tests\TestCase;

class PricingTest extends TestCase
{
    private function type(int $id, int $price, string $currency = 'MUR'): TicketType
    {
        $type = new TicketType;
        $type->id = $id;
        $type->public_id = 'type-'.$id;
        $type->name = 'Type '.$id;
        $type->currency = $currency;
        $type->unit_amount_minor = $price;

        return $type;
    }

    private function promo(string $type, int $value, ?string $currency = null, array $onlyFor = []): PromoCode
    {
        $promo = new PromoCode;
        $promo->code = 'TEST';
        $promo->discount_type = $type;
        $promo->discount_value = $value;
        $promo->currency = $currency;
        $promo->setRelation('ticketTypes', collect($onlyFor));

        return $promo;
    }

    private function pricing(int $feeBps = 500, int $taxBps = 0): Pricing
    {
        return new Pricing(new Repository(['tickora' => ['platform_fee_bps' => $feeBps, 'booking_fee_tax_bps' => $taxBps]]));
    }

    public function test_fee_is_a_percentage_of_the_ticket_subtotal(): void
    {
        $quote = $this->pricing()->quote('MUR', [['type' => $this->type(1, 85000), 'quantity' => 2]]);

        $this->assertSame(170000, $quote->subtotal->amount);
        $this->assertSame(0, $quote->discount->amount);
        $this->assertSame(8500, $quote->fee->amount);
        $this->assertSame(178500, $quote->total->amount);
        $this->assertSame(2, $quote->quantity());
        $this->assertNull($quote->promoCode);
    }

    public function test_tax_on_the_fee_is_added_when_configured(): void
    {
        $quote = $this->pricing(500, 1500)->quote('MUR', [['type' => $this->type(1, 100000), 'quantity' => 1]]);

        $this->assertSame(5000, $quote->fee->amount);
        $this->assertSame(750, $quote->tax->amount);
        $this->assertSame(105750, $quote->total->amount);
    }

    public function test_line_amounts_always_add_up_to_the_totals(): void
    {
        $quote = $this->pricing(333, 1500)->quote('MUR', [
            ['type' => $this->type(1, 33333), 'quantity' => 3],
            ['type' => $this->type(2, 77777), 'quantity' => 1],
            ['type' => $this->type(3, 10001), 'quantity' => 7],
        ], $this->promo('percent', 1234));

        $sum = fn (string $field): int => array_sum(array_map(fn ($line) => $line->{$field}->amount, $quote->lines));

        $this->assertSame($quote->subtotal->amount, $sum('subtotal'));
        $this->assertSame($quote->discount->amount, $sum('discount'));
        $this->assertSame($quote->fee->amount, $sum('fee'));
        $this->assertSame($quote->tax->amount, $sum('tax'));
        $this->assertSame(
            $quote->total->amount,
            $quote->subtotal->amount - $quote->discount->amount + $quote->fee->amount + $quote->tax->amount,
        );
    }

    public function test_percentage_promo_reduces_the_fee_base(): void
    {
        $quote = $this->pricing()->quote('MUR', [['type' => $this->type(1, 100000), 'quantity' => 1]], $this->promo('percent', 2000));

        $this->assertSame(20000, $quote->discount->amount);
        $this->assertSame(4000, $quote->fee->amount);
        $this->assertSame(84000, $quote->total->amount);
        $this->assertNotNull($quote->promoCode);
    }

    public function test_fixed_promo_never_exceeds_the_subtotal_and_free_orders_pay_no_fee(): void
    {
        $quote = $this->pricing()->quote('MUR', [['type' => $this->type(1, 30000), 'quantity' => 1]], $this->promo('fixed', 50000, 'MUR'));

        $this->assertSame(30000, $quote->discount->amount);
        $this->assertSame(0, $quote->fee->amount);
        $this->assertTrue($quote->isFree());
    }

    public function test_restricted_promo_only_discounts_eligible_ticket_types(): void
    {
        $regular = $this->type(1, 50000);
        $vip = $this->type(2, 200000);

        $quote = $this->pricing()->quote('MUR', [
            ['type' => $regular, 'quantity' => 2],
            ['type' => $vip, 'quantity' => 1],
        ], $this->promo('percent', 1000, null, [$vip]));

        $this->assertSame(0, $quote->lines[0]->discount->amount);
        $this->assertSame(20000, $quote->lines[1]->discount->amount);
    }

    public function test_promo_that_matches_nothing_is_not_attached(): void
    {
        $quote = $this->pricing()->quote('MUR', [['type' => $this->type(1, 50000), 'quantity' => 1]], $this->promo('percent', 1000, null, [$this->type(9, 1)]));

        $this->assertSame(0, $quote->discount->amount);
        $this->assertNull($quote->promoCode);
    }

    public function test_mixed_currencies_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pricing()->quote('MUR', [['type' => $this->type(1, 50000, 'KES'), 'quantity' => 1]]);
    }
}
