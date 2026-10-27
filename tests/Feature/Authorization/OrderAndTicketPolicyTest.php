<?php

namespace Tests\Feature\Authorization;

use App\Enums\OrganizerRole;
use App\Enums\PlatformRole;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Reference;
use App\Support\TicketToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OrderAndTicketPolicyTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_customers_see_only_their_own_orders(): void
    {
        $order = Order::factory()->create();
        $buyer = User::query()->findOrFail($order->user_id);
        $stranger = User::factory()->create();

        $this->assertTrue($buyer->can('view', $order));
        $this->assertFalse($stranger->can('view', $order));
    }

    public function test_the_organisers_sales_team_sees_orders_but_gate_staff_do_not(): void
    {
        $order = Order::factory()->create();
        $organizer = $order->organizer;

        $this->assertTrue($this->memberOf($organizer, OrganizerRole::Manager)->can('view', $order));
        $this->assertFalse($this->memberOf($organizer, OrganizerRole::Staff)->can('view', $order));
        $this->assertFalse($this->ownerOf($this->organizer())->can('view', $order));
        $this->assertTrue($this->platformUser(PlatformRole::Finance)->can('view', $order));
    }

    public function test_refunds_can_be_requested_only_for_paid_orders(): void
    {
        $paid = Order::factory()->create();
        $pending = Order::factory()->pendingPayment()->create();

        $this->assertTrue(User::query()->findOrFail($paid->user_id)->can('requestRefund', $paid));
        $this->assertFalse(User::query()->findOrFail($pending->user_id)->can('requestRefund', $pending));
        $this->assertFalse(User::factory()->create()->can('requestRefund', $paid));
    }

    public function test_refund_approval_needs_finance_and_a_second_person(): void
    {
        $order = Order::factory()->create();
        $finance = $this->platformUser(PlatformRole::Finance);
        $otherFinance = $this->platformUser(PlatformRole::Finance);

        $refund = new Refund;
        $refund->order_id = $order->id;
        $refund->idempotency_key = (string) Str::uuid();
        $refund->currency = $order->currency;
        $refund->amount_minor = 85000;
        $refund->requested_by = $finance->id;
        $refund->save();

        $this->assertFalse($finance->can('approve', $refund), 'requester approved their own refund');
        $this->assertTrue($otherFinance->can('approve', $refund));
        $this->assertFalse($this->platformUser(PlatformRole::Support)->can('approve', $refund));
        $this->assertFalse($this->ownerOf($order->organizer)->can('approve', $refund));

        $refund->transitionTo(RefundStatus::Approved);

        $this->assertFalse($otherFinance->can('approve', $refund), 'already approved');
    }

    public function test_only_the_holder_or_buyer_can_open_a_ticket_qr(): void
    {
        $order = Order::factory()->create();
        $buyer = User::query()->findOrFail($order->user_id);
        $holder = User::factory()->create();
        $ticket = $this->issueTicket($order, $holder);

        $this->assertTrue($buyer->can('view', $ticket));
        $this->assertTrue($holder->can('download', $ticket));
        $this->assertFalse(User::factory()->create()->can('view', $ticket));

        // Even the organiser's owner never gets the bearer credential.
        $this->assertFalse($this->ownerOf($order->organizer)->can('view', $ticket));
    }

    public function test_qr_hashes_never_appear_in_serialised_tickets(): void
    {
        $order = Order::factory()->create();
        $ticket = $this->issueTicket($order, null);

        $this->assertArrayNotHasKey('qr_token_hash', $ticket->toArray());
    }

    private function issueTicket(Order $order, ?User $holder): Ticket
    {
        $type = TicketType::factory()->create(['event_id' => $order->event_id]);

        $item = new OrderItem;
        $item->order_id = $order->id;
        $item->ticket_type_id = $type->id;
        $item->ticket_name_snapshot = $type->name;
        $item->quantity = 1;
        $item->unit_amount_minor = $type->unit_amount_minor;
        $item->line_total_minor = $type->unit_amount_minor;
        $item->save();

        $ticket = new Ticket;
        $ticket->reference = Reference::ticket($order->reference, 1);
        $ticket->order_id = $order->id;
        $ticket->order_item_id = $item->id;
        $ticket->event_id = $order->event_id;
        $ticket->ticket_type_id = $type->id;
        $ticket->holder_user_id = $holder?->id;
        $ticket->qr_token_hash = TicketToken::issue()->hash();
        $ticket->issued_at = now();
        $ticket->save();

        return $ticket;
    }
}
