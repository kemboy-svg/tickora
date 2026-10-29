<?php

namespace Tests\Feature\Checkout;

use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketTypeStatus;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Event $event;

    private TicketType $regular;

    private TicketType $vip;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tickora.platform_fee_bps' => 500, 'tickora.booking_fee_tax_bps' => 0]);

        $this->event = $this->publishedEventFor($this->organizer());
        $this->regular = TicketType::factory()->create(['event_id' => $this->event->id, 'quantity_total' => 10]);
        $this->vip = TicketType::factory()->vip()->create(['event_id' => $this->event->id, 'sort_order' => 1]);
    }

    /**
     * @param  array<string, int>  $items
     */
    private function hold(User $user, array $items, ?string $promo = null): TestResponse
    {
        return $this->actingAs($user)->post('/checkout', [
            'event' => $this->event->public_id,
            'items' => $items,
            'promo_code' => $promo,
        ]);
    }

    private function latestCheckout(User $user): Checkout
    {
        return Checkout::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    public function test_guests_must_sign_in_to_check_out(): void
    {
        $this->post('/checkout', ['event' => $this->event->public_id, 'items' => [$this->regular->public_id => 1]])
            ->assertRedirect('/login');

        $this->assertSame(0, Checkout::query()->count());
    }

    public function test_a_checkout_holds_tickets_at_server_side_prices(): void
    {
        $buyer = User::factory()->create();

        $this->hold($buyer, [$this->regular->public_id => 2, $this->vip->public_id => 1])
            ->assertSessionHasNoErrors();

        $checkout = $this->latestCheckout($buyer);

        $this->assertSame(CheckoutStatus::Open, $checkout->status);
        $this->assertSame(85000 * 2 + 240000, $checkout->subtotal_minor);
        $this->assertSame(20500, $checkout->fees_minor);
        $this->assertSame(430500, $checkout->total_minor);
        $this->assertSame($buyer->email, $checkout->customer_email);
        $this->assertTrue($checkout->expires_at->between(now()->addMinutes(9), now()->addMinutes(11)));

        $reservations = $checkout->reservations()->orderBy('id')->get();
        $this->assertCount(2, $reservations);
        $this->assertSame('Regular', $reservations[0]->ticket_name_snapshot);
        $this->assertSame(2, $reservations[0]->quantity);
        $this->assertSame(85000, $reservations[0]->unit_amount_minor);

        $this->actingAs($buyer)->get("/checkout/{$checkout->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('checkout/Show')
                ->where('checkout.status', 'open')
                ->where('checkout.total.formatted', 'MUR 4,305.00')
                ->has('checkout.lines', 2));
    }

    public function test_held_tickets_reduce_public_availability(): void
    {
        $this->hold(User::factory()->create(), [$this->regular->public_id => 4]);

        $this->get("/events/{$this->event->slug}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.ticket_types.0.remaining', 6));
    }

    public function test_cannot_hold_more_than_remain(): void
    {
        $this->regular->forceFill(['quantity_sold' => 7])->save();
        $this->hold(User::factory()->create(), [$this->regular->public_id => 2]);

        $this->hold(User::factory()->create(), [$this->regular->public_id => 2])
            ->assertSessionHasErrors(['items' => 'Only 1 Regular ticket is left.']);

        $this->hold(User::factory()->create(), [$this->regular->public_id => 1])
            ->assertSessionHasNoErrors();

        $this->hold(User::factory()->create(), [$this->regular->public_id => 1])
            ->assertSessionHasErrors(['items' => 'Regular just sold out.']);
    }

    public function test_expired_holds_do_not_count_against_availability(): void
    {
        $this->regular->forceFill(['quantity_total' => 2])->save();
        $first = User::factory()->create();
        $this->hold($first, [$this->regular->public_id => 2]);

        $this->travel(11)->minutes();

        $this->hold(User::factory()->create(), [$this->regular->public_id => 2])
            ->assertSessionHasNoErrors();
    }

    public function test_quantity_rules_are_enforced(): void
    {
        $buyer = User::factory()->create();
        $this->regular->forceFill(['min_per_order' => 2, 'max_per_order' => 4])->save();

        $this->hold($buyer, [$this->regular->public_id => 1])->assertSessionHasErrors('items');
        $this->hold($buyer, [$this->regular->public_id => 5])->assertSessionHasErrors('items');
        $this->hold($buyer, [$this->regular->public_id => 0])->assertSessionHasErrors(['items' => 'Choose at least one ticket.']);

        config(['tickora.max_tickets_per_order' => 5]);
        $this->hold($buyer, [$this->regular->public_id => 4, $this->vip->public_id => 2])
            ->assertSessionHasErrors(['items' => 'You can buy at most 5 tickets in one order.']);

        $this->assertSame(0, Checkout::query()->count());
    }

    public function test_ticket_types_of_other_events_and_closed_sales_are_rejected(): void
    {
        $buyer = User::factory()->create();
        $otherType = TicketType::factory()->create(['event_id' => $this->publishedEventFor($this->organizer())->id]);

        $this->hold($buyer, [$otherType->public_id => 1])->assertSessionHasErrors('items');

        $this->regular->forceFill(['status' => TicketTypeStatus::Paused])->save();
        $this->hold($buyer, [$this->regular->public_id => 1])->assertSessionHasErrors('items');

        $this->event->forceFill(['sales_ends_at' => now()->subMinute()])->save();
        $this->hold($buyer, [$this->vip->public_id => 1])->assertSessionHasErrors('event');
    }

    public function test_draft_events_cannot_be_bought(): void
    {
        $draft = Event::factory()->forOrganizer($this->organizer())->create();
        $type = TicketType::factory()->create(['event_id' => $draft->id]);

        $this->actingAs(User::factory()->create())
            ->post('/checkout', ['event' => $draft->public_id, 'items' => [$type->public_id => 1]])
            ->assertNotFound();
    }

    public function test_starting_again_releases_the_previous_hold(): void
    {
        $buyer = User::factory()->create();
        $this->hold($buyer, [$this->regular->public_id => 3]);
        $first = $this->latestCheckout($buyer);

        $this->hold($buyer, [$this->regular->public_id => 1]);

        $this->assertSame(CheckoutStatus::Cancelled, $first->fresh()?->status);
        $this->assertSame(1, (int) InventoryReservation::query()->holding()->sum('quantity'));
    }

    public function test_only_the_buyer_can_see_or_change_their_checkout(): void
    {
        $buyer = User::factory()->create();
        $this->hold($buyer, [$this->regular->public_id => 1]);
        $checkout = $this->latestCheckout($buyer);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get("/checkout/{$checkout->public_id}")->assertForbidden();
        $this->actingAs($intruder)->post("/checkout/{$checkout->public_id}/confirm")->assertForbidden();
        $this->actingAs($intruder)->delete("/checkout/{$checkout->public_id}")->assertForbidden();
        $this->actingAs($intruder)->post("/checkout/{$checkout->public_id}/promo", ['promo_code' => 'X'])->assertForbidden();

        $this->assertSame(CheckoutStatus::Open, $checkout->fresh()?->status);
    }

    public function test_releasing_returns_tickets_to_sale(): void
    {
        $buyer = User::factory()->create();
        $this->hold($buyer, [$this->regular->public_id => 3]);
        $checkout = $this->latestCheckout($buyer);

        $this->actingAs($buyer)->delete("/checkout/{$checkout->public_id}")
            ->assertRedirect("/events/{$this->event->slug}");

        $this->assertSame(CheckoutStatus::Cancelled, $checkout->fresh()?->status);
        $this->assertSame(ReservationStatus::Released, $checkout->reservations()->firstOrFail()->status);
        $this->assertSame(0, (int) InventoryReservation::query()->holding()->sum('quantity'));
    }

    public function test_confirming_creates_a_pending_order_from_the_snapshots(): void
    {
        $buyer = User::factory()->create(['name' => 'Asha Ramdin']);
        $this->hold($buyer, [$this->regular->public_id => 2]);
        $checkout = $this->latestCheckout($buyer);

        // A later price edit must not change what this customer pays.
        $this->regular->forceFill(['unit_amount_minor' => 99999])->save();

        // Confirming goes straight on to the payment provider's page.
        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")
            ->assertRedirectContains('/sandbox/payments/fake_pay_');

        $order = Order::query()->where('checkout_id', $checkout->id)->firstOrFail();

        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertMatchesRegularExpression('/^TKR-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $order->reference);
        $this->assertSame($this->event->organizer_id, $order->organizer_id);
        $this->assertSame('Asha Ramdin', $order->customer_name);
        $this->assertSame(170000, $order->subtotal_minor);
        $this->assertSame(8500, $order->platform_fee_minor);
        $this->assertSame(178500, $order->total_minor);

        $item = $order->items()->firstOrFail();
        $this->assertSame('Regular', $item->ticket_name_snapshot);
        $this->assertSame(85000, $item->unit_amount_minor);
        $this->assertSame(170000, $item->line_total_minor);
        $this->assertSame(8500, $item->fee_minor);

        $checkout->refresh();
        $this->assertSame(CheckoutStatus::AwaitingPayment, $checkout->status);
        $this->assertTrue($checkout->expires_at->gt(now()->addMinutes(14)));
        $this->assertTrue($checkout->reservations()->firstOrFail()->expires_at->gt(now()->addMinutes(14)));

        // Confirming again is harmless and returns the same order.
        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm");
        $this->assertSame(1, Order::query()->count());

        $this->actingAs($buyer)->get("/checkout/{$checkout->public_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkout.status', 'awaiting_payment')
                ->where('checkout.order.reference', $order->reference));
    }

    public function test_an_expired_checkout_cannot_be_confirmed(): void
    {
        $buyer = User::factory()->create();
        $this->hold($buyer, [$this->regular->public_id => 1]);
        $checkout = $this->latestCheckout($buyer);

        $this->travel(11)->minutes();

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")
            ->assertSessionHasErrors('checkout');

        $this->assertSame(0, Order::query()->count());

        $this->actingAs($buyer)->get("/checkout/{$checkout->public_id}")
            ->assertInertia(fn (Assert $page) => $page->where('checkout.status', 'expired'));
    }

    public function test_cancelling_an_awaiting_checkout_cancels_its_order(): void
    {
        $buyer = User::factory()->create();
        $this->hold($buyer, [$this->regular->public_id => 1]);
        $checkout = $this->latestCheckout($buyer);
        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm");

        $this->actingAs($buyer)->delete("/checkout/{$checkout->public_id}");

        $this->assertSame(OrderStatus::Cancelled, Order::query()->firstOrFail()->status);
        $this->assertSame(0, (int) InventoryReservation::query()->holding()->sum('quantity'));
    }

    public function test_promo_codes_discount_the_checkout(): void
    {
        $promo = new PromoCode([
            'code' => 'early20',
            'discount_type' => 'percent',
            'discount_value' => 2000,
        ]);
        $promo->organizer_id = $this->event->organizer_id;
        $promo->save();

        $buyer = User::factory()->create();
        $this->hold($buyer, [$this->regular->public_id => 2], 'Early20')->assertSessionHasNoErrors();
        $checkout = $this->latestCheckout($buyer);

        $this->assertSame($promo->id, $checkout->promo_code_id);
        $this->assertSame(34000, $checkout->discount_minor);
        $this->assertSame(6800, $checkout->fees_minor);
        $this->assertSame(142800, $checkout->total_minor);

        // Removing and re-applying on the checkout page re-prices it.
        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/promo", ['promo_code' => ''])->assertSessionHasNoErrors();
        $this->assertSame(178500, $checkout->fresh()?->total_minor);

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/promo", ['promo_code' => 'EARLY20'])->assertSessionHasNoErrors();
        $this->assertSame(142800, $checkout->fresh()?->total_minor);
    }

    public function test_invalid_promo_codes_are_explained(): void
    {
        $buyer = User::factory()->create();
        $other = $this->organizer();

        $foreign = new PromoCode(['code' => 'THEIRS', 'discount_type' => 'percent', 'discount_value' => 1000]);
        $foreign->organizer_id = $other->id;
        $foreign->save();

        $expired = new PromoCode(['code' => 'OLD', 'discount_type' => 'percent', 'discount_value' => 1000, 'ends_at' => now()->subDay()]);
        $expired->organizer_id = $this->event->organizer_id;
        $expired->save();

        $paused = new PromoCode(['code' => 'PAUSED', 'discount_type' => 'percent', 'discount_value' => 1000, 'is_active' => false]);
        $paused->organizer_id = $this->event->organizer_id;
        $paused->save();

        $usedUp = new PromoCode(['code' => 'GONE', 'discount_type' => 'percent', 'discount_value' => 1000, 'max_redemptions' => 1]);
        $usedUp->organizer_id = $this->event->organizer_id;
        $usedUp->redemptions_count = 1;
        $usedUp->save();

        $wrongCurrency = new PromoCode(['code' => 'KES100', 'discount_type' => 'fixed', 'discount_value' => 10000, 'currency' => 'KES']);
        $wrongCurrency->organizer_id = $this->event->organizer_id;
        $wrongCurrency->save();

        $vipOnly = new PromoCode(['code' => 'VIPONLY', 'discount_type' => 'percent', 'discount_value' => 1000]);
        $vipOnly->organizer_id = $this->event->organizer_id;
        $vipOnly->save();
        $vipOnly->ticketTypes()->attach($this->vip->id);

        foreach (['NOPE', 'THEIRS', 'OLD', 'PAUSED', 'GONE', 'KES100', 'VIPONLY'] as $code) {
            $this->hold($buyer, [$this->regular->public_id => 1], $code)
                ->assertSessionHasErrors('promo_code');
        }

        $this->assertSame(0, Checkout::query()->count());
    }

    public function test_expiry_sweep_releases_holds_and_cancels_unpaid_orders(): void
    {
        $buyer = User::factory()->create();
        $this->hold($buyer, [$this->regular->public_id => 1]);
        $open = $this->latestCheckout($buyer);

        $payer = User::factory()->create();
        $this->hold($payer, [$this->vip->public_id => 1]);
        $awaiting = $this->latestCheckout($payer);
        $this->actingAs($payer)->post("/checkout/{$awaiting->public_id}/confirm");

        $fresh = User::factory()->create();

        $this->travel(11)->minutes();
        $this->hold($fresh, [$this->regular->public_id => 1]);
        $live = $this->latestCheckout($fresh);

        $this->artisan('tickora:expire-holds')->assertSuccessful();

        $this->assertSame(CheckoutStatus::Expired, $open->fresh()?->status);
        $this->assertSame(ReservationStatus::Expired, $open->reservations()->firstOrFail()->status);
        $this->assertSame(CheckoutStatus::AwaitingPayment, $awaiting->fresh()?->status, 'payment window is 15 minutes');
        $this->assertSame(CheckoutStatus::Open, $live->fresh()?->status);

        $this->travel(5)->minutes();
        $this->artisan('tickora:expire-holds')->assertSuccessful();

        $this->assertSame(CheckoutStatus::Expired, $awaiting->fresh()?->status);
        $this->assertSame(OrderStatus::Cancelled, Order::query()->firstOrFail()->status);
    }
}
