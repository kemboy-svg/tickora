<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\OrganizerRole;
use App\Enums\PlatformRole;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organizer;
use App\Models\TicketType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class DashboardDataTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tickora.require_two_factor' => false, 'tickora.reporting_timezone' => 'Indian/Mauritius']);
    }

    private function sale(Event $event, TicketType $type, int $quantity, ?CarbonImmutable $paidAt = null, OrderStatus $status = OrderStatus::Paid, ?User $buyer = null): Order
    {
        $subtotal = $type->unit_amount_minor * $quantity;

        $order = Order::factory()->create([
            'event_id' => $event->id,
            'organizer_id' => $event->organizer_id,
            'currency' => $event->currency,
            'user_id' => ($buyer ?? User::factory()->create())->id,
            'subtotal_minor' => $subtotal,
            'discount_minor' => 0,
            'platform_fee_minor' => intdiv($subtotal, 20),
            'tax_minor' => 0,
            'total_minor' => $subtotal + intdiv($subtotal, 20),
            'status' => $status,
            'paid_at' => $status->wasPaid() ? ($paidAt ?? now()) : null,
        ]);

        $item = new OrderItem;
        $item->order_id = $order->id;
        $item->ticket_type_id = $type->id;
        $item->ticket_name_snapshot = $type->name;
        $item->quantity = $quantity;
        $item->unit_amount_minor = $type->unit_amount_minor;
        $item->line_total_minor = $subtotal;
        $item->save();

        return $order;
    }

    /**
     * @return array{Organizer, Event, TicketType}
     */
    private function sellingOrganizer(): array
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $type = TicketType::factory()->create(['event_id' => $event->id, 'unit_amount_minor' => 100000, 'quantity_total' => 100, 'quantity_sold' => 7]);

        return [$organizer, $event, $type];
    }

    public function test_the_organiser_overview_shows_only_its_own_sales(): void
    {
        [$organizer, $event, $type] = $this->sellingOrganizer();

        $this->sale($event, $type, 3, CarbonImmutable::now()->subDays(2));
        $this->sale($event, $type, 4, CarbonImmutable::now()->subDays(60));
        $this->sale($event, $type, 5, null, OrderStatus::PendingPayment);
        $this->sale($event, $type, 6, null, OrderStatus::Cancelled);

        // Another organiser's sales must not leak in.
        [, $otherEvent, $otherType] = $this->sellingOrganizer();
        $this->sale($otherEvent, $otherType, 9);

        $this->actingAs($this->ownerOf($organizer))->get('/organizer/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organizer/Dashboard')
                ->where('tickets.last_30_days', 3)
                ->where('tickets.all_time', 7)
                ->where('orders_last_30_days', 1)
                ->where('totals.last_30_days.0.ticket_sales.amount_minor', 300000)
                ->where('totals.all_time.0.ticket_sales.amount_minor', 700000)
                ->where('totals.all_time.0.orders', 2)
                ->has('daily', 30)
                ->where('daily.27.tickets', 3)
                ->where('events.0.id', $event->public_id)
                ->where('events.0.sold', 7)
                ->where('events.0.sales.amount_minor', 700000)
                ->has('recent_orders', 2)
                ->missing('recent_orders.0.total'));
    }

    public function test_an_organiser_with_no_sales_sees_zeros_not_errors(): void
    {
        $organizer = $this->organizer();

        $this->actingAs($this->ownerOf($organizer))->get('/organizer/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.all_time', 0)
                ->where('totals.all_time', [])
                ->has('daily', 30)
                ->where('events', []));
    }

    public function test_the_platform_overview_counts_every_organiser(): void
    {
        [, $event, $type] = $this->sellingOrganizer();
        [, $otherEvent, $otherType] = $this->sellingOrganizer();
        $this->sale($event, $type, 2);
        $this->sale($otherEvent, $otherType, 5);
        Event::factory()->pendingReview()->create();

        $this->actingAs($this->platformUser(PlatformRole::Admin))->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Dashboard')
                ->where('tickets.last_30_days', 7)
                ->where('orders.last_30_days', 2)
                ->where('totals.last_30_days.0.gross.amount_minor', 735000)
                ->where('totals.last_30_days.0.fees.amount_minor', 35000)
                ->where('queues.events_in_review', 1)
                ->where('top_organizers.0.tickets', 5)
                ->has('recent_orders', 2)
                ->has('daily', 30));
    }

    public function test_the_customer_dashboard_lists_their_own_orders_and_holds(): void
    {
        [, $event, $type] = $this->sellingOrganizer();
        $buyer = User::factory()->create();

        $paid = $this->sale($event, $type, 2, null, OrderStatus::Paid, $buyer);
        $this->sale($event, $type, 1); // someone else's

        $hold = new Checkout;
        $hold->user_id = $buyer->id;
        $hold->event_id = $event->id;
        $hold->customer_email = $buyer->email;
        $hold->currency = $event->currency;
        $hold->total_minor = 100000;
        $hold->expires_at = now()->addMinutes(8);
        $hold->save();

        $this->actingAs($buyer)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.upcoming_events', 1)
                ->where('stats.upcoming_tickets', 2)
                ->has('upcoming', 1)
                ->where('upcoming.0.reference', $paid->reference)
                ->has('holds', 1)
                ->where('holds.0.id', $hold->public_id));
    }

    public function test_managers_see_the_overview_and_staff_do_not(): void
    {
        [$organizer] = $this->sellingOrganizer();

        $this->actingAs($this->memberOf($organizer, OrganizerRole::Manager))
            ->get('/organizer/dashboard')
            ->assertOk();

        $this->actingAs($this->memberOf($organizer, OrganizerRole::Staff))
            ->get('/organizer/dashboard')
            ->assertRedirect();
    }
}
