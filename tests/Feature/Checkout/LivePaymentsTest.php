<?php

namespace Tests\Feature\Checkout;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\PlatformRole;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\FakesPaymentProviders;
use Tests\TestCase;

/**
 * Checkout on a live server: APP_ENV=production with live provider keys.
 */
class LivePaymentsTest extends TestCase
{
    use CreatesTenants;
    use FakesPaymentProviders;
    use RefreshDatabase;

    /**
     * A made-up live key, assembled at runtime so secret scanners don't
     * mistake the source for a leaked key.
     */
    private string $stripeLive;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->fakeProviderApis();
        $this->stripeLive = implode('_', ['sk', 'live', 'notARealKeyTickoraTests']);
    }

    private function production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->assertTrue($this->app->isProduction());

        // Laravel only skips CSRF checks in the testing environment.
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function liveStripe(bool $default = false): PaymentGatewaySetting
    {
        $setting = $this->configureProvider(PaymentProvider::Stripe, default: $default);
        $setting->secret_key = $this->stripeLive;
        $setting->save();

        return $setting;
    }

    private function eventWith(?PaymentProvider $provider): Event
    {
        $event = Event::factory()->forOrganizer($this->organizer())->published()->create();
        $event->forceFill(['payment_provider' => $provider])->save();
        TicketType::factory()->create(['event_id' => $event->id, 'quantity_total' => 10]);

        return $event->fresh() ?? $event;
    }

    private function hold(User $buyer, Event $event): Checkout
    {
        $type = $event->ticketTypes()->firstOrFail();

        $this->actingAs($buyer)->post('/checkout', ['event' => $event->public_id, 'items' => [$type->public_id => 1]])
            ->assertSessionHasNoErrors();

        return Checkout::query()->where('user_id', $buyer->id)->latest('id')->firstOrFail();
    }

    public function test_live_stripe_keys_take_payments_without_choosing_a_default(): void
    {
        $this->production();
        $this->liveStripe();
        $event = $this->eventWith(null);
        $buyer = User::factory()->create();
        $checkout = $this->hold($buyer, $event);

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

        $payment = Payment::query()->firstOrFail();
        $this->assertSame('stripe', $payment->provider);
        $this->assertSame('live', $payment->provider_mode);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'checkout/sessions')
            && $r->hasHeader('Authorization', 'Bearer '.$this->stripeLive));

        // Paid on Stripe; Stripe's webhook completes the order.
        $this->stripeSessionStatus = 'complete';
        $this->stripePaymentStatus = 'paid';
        $hook = $this->stripeWebhook('checkout.session.completed', [
            'id' => 'cs_test_123', 'status' => 'complete', 'payment_status' => 'paid',
            'amount_total' => $payment->amount_minor, 'currency' => 'mur',
        ]);
        $this->call('POST', '/webhooks/payments/stripe', [], [], [], $hook['server'], $hook['payload'])->assertOk();

        $this->assertSame(OrderStatus::Paid, Order::query()->firstOrFail()->status);
    }

    public function test_events_left_on_the_sandbox_use_a_real_provider_in_production(): void
    {
        $this->production();
        $this->liveStripe();
        $event = $this->eventWith(PaymentProvider::Fake);
        $buyer = User::factory()->create();
        $checkout = $this->hold($buyer, $event);

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');
    }

    public function test_without_any_provider_production_explains_instead_of_using_the_sandbox(): void
    {
        $this->production();
        $event = $this->eventWith(null);
        $buyer = User::factory()->create();
        $checkout = $this->hold($buyer, $event);

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")
            ->assertSessionHasErrors('checkout');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_an_open_test_attempt_is_not_reused_after_switching_to_live_keys(): void
    {
        $this->configureProvider(PaymentProvider::Stripe, default: true);
        $event = $this->eventWith(null);
        $buyer = User::factory()->create();
        $checkout = $this->hold($buyer, $event);

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

        // The admin pastes the live keys while the buyer is still deciding.
        $this->liveStripe(default: true);
        $this->stripeSessionId = 'cs_live_456';

        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/pay")
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_live_456');

        $payments = Payment::query()->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame(PaymentStatus::Failed, $payments[0]->status);
        $this->assertSame('keys_changed', $payments[0]->failure_code);
        $this->assertSame('live', $payments[1]->provider_mode);
        $this->assertNotSame($payments[0]->idempotency_key, $payments[1]->idempotency_key);
    }

    public function test_a_payment_page_that_failed_to_open_is_retried_as_a_new_attempt(): void
    {
        $this->configureProvider(PaymentProvider::Paystack);
        $organizer = $this->organizer();
        $organizer->forceFill(['default_currency' => 'KES'])->save();
        $event = Event::factory()->forOrganizer($organizer)->published()->create();
        $event->forceFill(['payment_provider' => PaymentProvider::Paystack])->save();
        TicketType::factory()->create(['event_id' => $event->id, 'quantity_total' => 10]);
        $buyer = User::factory()->create();
        $checkout = $this->hold($buyer, $event);

        $this->providersDown = true;
        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/confirm")->assertSessionHasErrors('checkout');

        $this->providersDown = false;
        $this->actingAs($buyer)->post("/checkout/{$checkout->public_id}/pay")
            ->assertRedirect('https://checkout.paystack.com/abc123');

        $payments = Payment::query()->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame('checkout_not_created', $payments[0]->failure_code);
        $this->assertStringEndsWith(':attempt:2', $payments[1]->idempotency_key);
    }

    public function test_admins_see_whether_the_server_is_ready_for_live_payments(): void
    {
        config(['tickora.require_two_factor' => false]);
        $this->production();
        $admin = $this->platformUser(PlatformRole::Admin);
        $this->configureProvider(PaymentProvider::Stripe);

        $this->actingAs($admin)->get('/admin/payments')
            ->assertInertia(fn (Assert $page) => $page
                ->where('checks.0.level', 'problem')
                ->where('checks.0.message', fn (string $m): bool => str_contains($m, 'test keys')));

        $this->liveStripe();
        config(['app.url' => 'https://tickets.example.com']);

        $this->actingAs($admin)->get('/admin/payments')
            ->assertInertia(fn (Assert $page) => $page
                ->has('checks', 1)
                ->where('checks.0.level', 'ok'));
    }
}
