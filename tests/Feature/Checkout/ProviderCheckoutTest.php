<?php

namespace Tests\Feature\Checkout;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\FakesPaymentProviders;
use Tests\TestCase;

class ProviderCheckoutTest extends TestCase
{
    use CreatesTenants;
    use FakesPaymentProviders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tickora.platform_fee_bps' => 500]);
        Mail::fake();
        $this->fakeProviderApis();
    }

    private function eventWith(?PaymentProvider $provider, string $currency = 'MUR'): Event
    {
        $organizer = $this->organizer();
        $organizer->forceFill(['default_currency' => $currency])->save();

        $event = Event::factory()->forOrganizer($organizer)->published()->create();
        $event->forceFill(['payment_provider' => $provider])->save();
        TicketType::factory()->create(['event_id' => $event->id, 'quantity_total' => 10]);

        return $event->fresh() ?? $event;
    }

    private function confirm(User $buyer, Event $event): Checkout
    {
        $type = $event->ticketTypes()->firstOrFail();

        $this->actingAs($buyer)->post('/checkout', ['event' => $event->public_id, 'items' => [$type->public_id => 2]])
            ->assertSessionHasNoErrors();

        return Checkout::query()->where('user_id', $buyer->id)->latest('id')->firstOrFail();
    }

    public function test_an_event_set_to_stripe_pays_through_stripe_checkout(): void
    {
        $this->configureProvider(PaymentProvider::Stripe);
        $event = $this->eventWith(PaymentProvider::Stripe);
        $buyer = User::factory()->create();
        $checkout = $this->confirm($buyer, $event);

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

        $payment = Payment::query()->firstOrFail();
        $this->assertSame('stripe', $payment->provider);
        $this->assertSame('cs_test_123', $payment->provider_payment_id);

        // Pressing Pay again goes back to the same Stripe page without a new session.
        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/pay")
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');
        Http::assertSentCount(1);

        // The customer pays on Stripe and comes back before any webhook arrives.
        $this->stripeSessionStatus = 'complete';
        $this->stripePaymentStatus = 'paid';

        $order = Order::query()->firstOrFail();
        $this->actingAs($buyer)->get("/checkout/{$checkout->public_id}")
            ->assertRedirect("/orders/{$order->public_id}");

        $this->assertSame(OrderStatus::Paid, $order->fresh()?->status);
        $this->assertSame(2, Ticket::query()->count());

        // Stripe's webhook arrives afterwards and changes nothing.
        $hook = $this->stripeWebhook('checkout.session.completed', [
            'id' => 'cs_test_123', 'status' => 'complete', 'payment_status' => 'paid',
            'amount_total' => $payment->amount_minor, 'currency' => 'mur',
        ]);
        $this->call('POST', '/webhooks/payments/stripe', [], [], [], $hook['server'], $hook['payload'])->assertOk();
        $this->assertSame(2, Ticket::query()->count());
    }

    public function test_a_stripe_webhook_alone_completes_the_order(): void
    {
        $this->configureProvider(PaymentProvider::Stripe);
        $event = $this->eventWith(PaymentProvider::Stripe);
        $buyer = User::factory()->create();
        $checkout = $this->confirm($buyer, $event);
        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm");
        $payment = Payment::query()->firstOrFail();

        $this->stripeSessionStatus = 'complete';
        $this->stripePaymentStatus = 'paid';

        $hook = $this->stripeWebhook('checkout.session.completed', [
            'id' => 'cs_test_123', 'status' => 'complete', 'payment_status' => 'paid',
            'amount_total' => $payment->amount_minor, 'currency' => 'mur',
        ]);
        $this->call('POST', '/webhooks/payments/stripe', [], [], [], $hook['server'], $hook['payload'])
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $this->assertSame(OrderStatus::Paid, Order::query()->firstOrFail()->status);

        $forged = $this->stripeWebhook('checkout.session.completed', ['id' => 'cs_test_123'], secret: 'whsec_attacker');
        $this->call('POST', '/webhooks/payments/stripe', [], [], [], $forged['server'], $forged['payload'])->assertStatus(400);
    }

    public function test_an_event_set_to_paystack_pays_through_paystack(): void
    {
        $this->configureProvider(PaymentProvider::Paystack);
        $event = $this->eventWith(PaymentProvider::Paystack, 'KES');
        $buyer = User::factory()->create();
        $checkout = $this->confirm($buyer, $event);

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")
            ->assertRedirect('https://checkout.paystack.com/abc123');

        $payment = Payment::query()->firstOrFail();
        $this->assertSame('paystack', $payment->provider);
        $this->assertSame('KES', $payment->currency);

        $this->paystackStatus = 'success';

        $hook = $this->paystackWebhook('charge.success', [
            'id' => 4099260516,
            'reference' => $payment->provider_payment_id,
            'status' => 'success',
            'amount' => $payment->amount_minor,
            'currency' => 'KES',
        ]);
        $this->call('POST', '/webhooks/payments/paystack', [], [], [], $hook['server'], $hook['payload'])->assertOk();

        $this->assertSame(OrderStatus::Paid, Order::query()->firstOrFail()->status);
        $this->assertSame(2, Ticket::query()->count());
    }

    public function test_events_without_a_choice_use_the_admin_default(): void
    {
        $this->configureProvider(PaymentProvider::Stripe, default: true);
        $event = $this->eventWith(null);
        $buyer = User::factory()->create();
        $checkout = $this->confirm($buyer, $event);

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');
    }

    public function test_a_switched_off_provider_stops_new_payments_with_a_clear_message(): void
    {
        $this->configureProvider(PaymentProvider::Stripe, enabled: false);
        $event = $this->eventWith(PaymentProvider::Stripe);
        $buyer = User::factory()->create();
        $checkout = $this->confirm($buyer, $event);

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")
            ->assertSessionHasErrors('checkout');

        $this->assertSame(0, Payment::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_provider_outage_leaves_the_customer_able_to_retry(): void
    {
        $this->configureProvider(PaymentProvider::Stripe);
        $event = $this->eventWith(PaymentProvider::Stripe);
        $buyer = User::factory()->create();
        $checkout = $this->confirm($buyer, $event);

        $this->providersDown = true;

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")
            ->assertSessionHasErrors('checkout');

        $this->assertSame(OrderStatus::PendingPayment, Order::query()->firstOrFail()->status);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'checkout/sessions'));
    }
}
