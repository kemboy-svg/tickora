<?php

namespace Tests\Feature\Checkout;

use App\Enums\CheckoutStatus;
use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Enums\WebhookProcessingStatus;
use App\Mail\OrderConfirmed;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\PromoCode;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Payments\Fake\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\TicketToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Event $event;

    private TicketType $regular;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tickora.platform_fee_bps' => 500, 'tickora.booking_fee_tax_bps' => 0]);
        Mail::fake();

        $this->event = $this->publishedEventFor($this->organizer());
        $this->regular = TicketType::factory()->create(['event_id' => $this->event->id, 'quantity_total' => 10]);
    }

    private function gateway(): FakePaymentGateway
    {
        $gateway = app(PaymentGatewayManager::class)->driver('fake');
        $this->assertInstanceOf(FakePaymentGateway::class, $gateway);

        return $gateway;
    }

    /**
     * Hold tickets and confirm, which sends the buyer to the payment page.
     *
     * @return array{Checkout, Payment}
     */
    private function checkoutAndConfirm(User $buyer, int $quantity = 2, ?string $promo = null): array
    {
        $this->actingAs($buyer)->post('/checkout', [
            'event' => $this->event->public_id,
            'items' => [$this->regular->public_id => $quantity],
            'promo_code' => $promo,
        ])->assertSessionHasNoErrors();

        $checkout = Checkout::query()->where('user_id', $buyer->id)->latest('id')->firstOrFail();

        $response = $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm");
        $payment = Payment::query()->latest('id')->firstOrFail();

        // A full-page visit to the provider's hosted page.
        $response->assertRedirect(url('/sandbox/payments/'.$payment->provider_payment_id));

        return [$checkout, $payment];
    }

    private function deliver(string $paymentId, PaymentStatus $status): TestResponse
    {
        $webhook = $this->gateway()->simulate($paymentId, $status);

        return $this->call('POST', '/webhooks/payments/fake', [], [], [], $this->serverHeaders($webhook['headers']), $webhook['payload']);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    public function test_a_successful_payment_issues_tickets_and_completes_everything(): void
    {
        $buyer = User::factory()->create(['name' => 'Asha Ramdin']);
        [$checkout, $payment] = $this->checkoutAndConfirm($buyer, 2);

        $this->assertSame(PaymentStatus::Created, $payment->status);
        $this->assertSame(178500, $payment->amount_minor);

        $this->deliver((string) $payment->provider_payment_id, PaymentStatus::Succeeded)
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $order = Order::query()->where('checkout_id', $checkout->id)->firstOrFail();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()?->status);
        $this->assertSame(CheckoutStatus::Completed, $checkout->fresh()?->status);
        $this->assertSame(ReservationStatus::Converted, $checkout->reservations()->firstOrFail()->status);
        $this->assertSame(2, $this->regular->fresh()?->quantity_sold);

        $tickets = Ticket::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $tickets);
        $this->assertSame($order->reference.'-01', $tickets[0]->reference);
        $this->assertSame(TicketStatus::Valid, $tickets[0]->status);
        $this->assertSame($buyer->id, $tickets[0]->holder_user_id);
        $this->assertNotSame($tickets[0]->qr_token_hash, $tickets[1]->qr_token_hash);

        // The stored QR secret round-trips and is found by scanning.
        $payload = $tickets[0]->qrPayload();
        $this->assertNotNull($payload);
        $this->assertTrue(Ticket::query()->forQrPayload($payload)->whereKey($tickets[0]->id)->exists());

        $ledger = LedgerEntry::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $ledger);
        $this->assertSame(LedgerEntryType::TicketSale, $ledger[0]->entry_type);
        $this->assertSame(170000, $ledger[0]->amount_minor);

        Mail::assertSent(OrderConfirmed::class, fn (OrderConfirmed $mail) => $mail->hasTo($buyer->email));

        $html = (new OrderConfirmed($order))->render();
        $this->assertStringContainsString($order->reference, $html);
        $this->assertStringContainsString('/orders/'.$order->public_id, $html);
        $this->assertStringNotContainsString((string) $payload, $html, 'emails never carry QR secrets');

        // Coming back from the provider lands on the tickets.
        $this->actingAs($buyer)->get("/checkout/{$checkout->public_id}")
            ->assertRedirect("/orders/{$order->public_id}");

        $this->actingAs($buyer)->get("/orders/{$order->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('orders/Show')
                ->has('tickets', 2)
                ->where('tickets.0.qr', $payload));
    }

    public function test_a_redelivered_webhook_changes_nothing(): void
    {
        [, $payment] = $this->checkoutAndConfirm(User::factory()->create(), 2);
        $webhook = $this->gateway()->simulate((string) $payment->provider_payment_id, PaymentStatus::Succeeded);

        foreach ([1, 2, 3] as $_) {
            $this->call('POST', '/webhooks/payments/fake', [], [], [], $this->serverHeaders($webhook['headers']), $webhook['payload'])->assertOk();
        }

        $this->assertSame(2, Ticket::query()->count());
        $this->assertSame(2, $this->regular->fresh()?->quantity_sold);
        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertSame(1, PaymentWebhookEvent::query()->count());
        $this->assertSame(WebhookProcessingStatus::Processed, PaymentWebhookEvent::query()->firstOrFail()->processing_status);
        Mail::assertSentCount(1);
    }

    public function test_forged_or_tampered_webhooks_are_rejected(): void
    {
        [, $payment] = $this->checkoutAndConfirm(User::factory()->create(), 1);
        $webhook = $this->gateway()->simulate((string) $payment->provider_payment_id, PaymentStatus::Succeeded);

        $tampered = str_replace('"succeeded"', '"succeeded" ', $webhook['payload']);
        $this->call('POST', '/webhooks/payments/fake', [], [], [], $this->serverHeaders($webhook['headers']), $tampered)
            ->assertStatus(400);

        $this->call('POST', '/webhooks/payments/fake', [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook['payload'])
            ->assertStatus(400);

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(PaymentStatus::Created, $payment->fresh()?->status);
    }

    public function test_a_success_the_provider_does_not_confirm_is_not_trusted(): void
    {
        [, $payment] = $this->checkoutAndConfirm(User::factory()->create(), 1);

        // Correctly signed, but the provider's own record says not captured.
        $webhook = $this->gateway()->signedWebhook([
            'id' => 'fake_evt_spoof',
            'type' => 'payment.succeeded',
            'data' => [
                'payment_id' => $payment->provider_payment_id,
                'status' => 'succeeded',
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
            ],
        ]);

        $this->call('POST', '/webhooks/payments/fake', [], [], [], $this->serverHeaders($webhook['headers']), $webhook['payload'])
            ->assertStatus(422);

        $this->assertSame(PaymentStatus::Created, $payment->fresh()?->status);
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(WebhookProcessingStatus::Failed, PaymentWebhookEvent::query()->firstOrFail()->processing_status);
    }

    public function test_a_declined_payment_can_be_retried_while_the_hold_lasts(): void
    {
        $buyer = User::factory()->create();
        [$checkout, $first] = $this->checkoutAndConfirm($buyer, 1);

        $this->deliver((string) $first->provider_payment_id, PaymentStatus::Failed)->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertSame(OrderStatus::PaymentFailed, $order->status);
        $this->assertSame('card_declined', $first->fresh()?->failure_code);
        $this->assertSame(CheckoutStatus::AwaitingPayment, $checkout->fresh()?->status);

        $this->actingAs($buyer)->get("/checkout/{$checkout->public_id}")
            ->assertInertia(fn (Assert $page) => $page->where('checkout.order.payment_declined', true));

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/pay")->assertRedirect();
        $second = Payment::query()->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);

        $this->deliver((string) $second->provider_payment_id, PaymentStatus::Succeeded)->assertOk();

        $this->assertSame(OrderStatus::Paid, $order->fresh()?->status);
        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_pressing_pay_twice_reuses_the_open_payment(): void
    {
        $buyer = User::factory()->create();
        [$checkout, $payment] = $this->checkoutAndConfirm($buyer, 1);

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/pay")
            ->assertRedirect(url('/sandbox/payments/'.$payment->provider_payment_id));

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_free_orders_complete_without_a_payment(): void
    {
        $this->regular->forceFill(['unit_amount_minor' => 0])->save();
        $buyer = User::factory()->create();

        $this->actingAs($buyer)->post('/checkout', ['event' => $this->event->public_id, 'items' => [$this->regular->public_id => 2]]);
        $checkout = Checkout::query()->latest('id')->firstOrFail();

        $order = null;
        $response = $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm");
        $order = Order::query()->firstOrFail();
        $response->assertRedirect("/orders/{$order->public_id}");

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(2, Ticket::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count(), 'nothing to credit for free tickets');
    }

    public function test_promo_use_is_recorded_when_paid(): void
    {
        $promo = new PromoCode(['code' => 'SAVE10', 'discount_type' => 'percent', 'discount_value' => 1000, 'max_redemptions' => 5]);
        $promo->organizer_id = $this->event->organizer_id;
        $promo->save();

        [, $payment] = $this->checkoutAndConfirm(User::factory()->create(), 2, 'SAVE10');
        $this->deliver((string) $payment->provider_payment_id, PaymentStatus::Succeeded)->assertOk();

        $this->assertSame(1, $promo->fresh()?->redemptions_count);
        $this->assertSame(17000, $promo->redemptions()->firstOrFail()->discount_minor);
        $this->assertSame(153000, LedgerEntry::query()->firstOrFail()->amount_minor);
    }

    public function test_a_late_payment_still_completes_if_tickets_remain(): void
    {
        $buyer = User::factory()->create();
        [$checkout, $payment] = $this->checkoutAndConfirm($buyer, 2);

        $this->travel(20)->minutes();
        $this->artisan('tickora:expire-holds');
        $this->assertSame(OrderStatus::Cancelled, Order::query()->firstOrFail()->status);

        $this->deliver((string) $payment->provider_payment_id, PaymentStatus::Succeeded)->assertOk();

        $this->assertSame(OrderStatus::Paid, Order::query()->firstOrFail()->status);
        $this->assertSame(2, Ticket::query()->count());
        $this->assertSame(CheckoutStatus::Completed, $checkout->fresh()?->status);
    }

    public function test_a_late_payment_for_sold_out_tickets_is_refunded_in_full(): void
    {
        $this->regular->forceFill(['quantity_total' => 2])->save();
        $late = User::factory()->create();
        [, $payment] = $this->checkoutAndConfirm($late, 2);

        $this->travel(20)->minutes();
        $this->artisan('tickora:expire-holds');

        // Someone else buys the last two tickets meanwhile.
        [, $theirs] = $this->checkoutAndConfirm(User::factory()->create(), 2);
        $this->deliver((string) $theirs->provider_payment_id, PaymentStatus::Succeeded)->assertOk();

        $this->deliver((string) $payment->provider_payment_id, PaymentStatus::Succeeded)->assertOk();

        $order = Order::query()->where('user_id', $late->id)->firstOrFail();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(0, Ticket::query()->where('order_id', $order->id)->count());
        $this->assertSame(2, $this->regular->fresh()?->quantity_sold, 'never oversold');

        $refund = $order->refunds()->firstOrFail();
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame($payment->amount_minor, $refund->amount_minor);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()?->status);
    }

    public function test_only_the_buyer_sees_qr_codes_and_the_sandbox_page(): void
    {
        $buyer = User::factory()->create();
        [, $payment] = $this->checkoutAndConfirm($buyer, 1);

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->get('/sandbox/payments/'.$payment->provider_payment_id)->assertNotFound();
        $this->actingAs($intruder)->post('/sandbox/payments/'.$payment->provider_payment_id, ['outcome' => 'succeed'])->assertNotFound();

        $this->actingAs($buyer)->get('/sandbox/payments/'.$payment->provider_payment_id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('sandbox/Pay'));

        // Paying on the sandbox page goes through the same webhook processing.
        $this->actingAs($buyer)->post('/sandbox/payments/'.$payment->provider_payment_id, ['outcome' => 'succeed'])
            ->assertRedirect();

        $order = Order::query()->firstOrFail();
        $ticket = Ticket::query()->firstOrFail();
        $this->assertSame(OrderStatus::Paid, $order->status);

        $this->actingAs($intruder)->get("/orders/{$order->public_id}")->assertForbidden();
        $this->actingAs($intruder)->get("/tickets/{$ticket->public_id}")->assertForbidden();

        // The organiser can see the order but not the QR secrets.
        $owner = $this->ownerOf($this->event->organizer);
        $this->actingAs($owner)->get("/orders/{$order->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tickets.0.qr', null));

        $this->actingAs($buyer)->get("/tickets/{$ticket->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tickets/Show')
                ->where('ticket.qr', fn (string $qr) => TicketToken::fromPayload($qr) !== null));

        $this->assertArrayNotHasKey('qr_token_ciphertext', $ticket->toArray());
    }
}
