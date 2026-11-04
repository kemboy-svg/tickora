<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Payments\Fake\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Event $event;

    private TicketType $regular;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->event = $this->publishedEventFor($this->organizer());
        $this->regular = TicketType::factory()->create(['event_id' => $this->event->id, 'quantity_total' => 10]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test phone')->plainTextToken;
    }

    /**
     * Token requests in one test must not reuse the previous request's user.
     */
    private function fresh(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /**
     * The phone's browser: no app token, no website session.
     */
    private function browser(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->withoutToken();
    }

    public function test_the_app_can_check_the_server_address(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJson(['app' => 'tickora', 'api_version' => 1]);
    }

    public function test_signing_up_returns_a_token_and_sends_the_confirmation_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Asha Ramdin',
            'email' => 'asha@example.com',
            'password' => 'a-long-Passw0rd!',
            'password_confirmation' => 'a-long-Passw0rd!',
            'device_name' => 'Pixel 8',
        ])->assertCreated()
            ->assertJsonPath('user.email', 'asha@example.com')
            ->assertJsonPath('user.email_verified', false);

        $this->assertIsString($response->json('token'));

        $user = User::query()->where('email', 'asha@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertSame('Pixel 8', $user->tokens()->firstOrFail()->name);
    }

    public function test_sign_up_rejects_a_taken_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'a-long-Passw0rd!',
            'password_confirmation' => 'a-long-Passw0rd!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_signing_in_checks_the_password(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'buyer@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'buyer@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.email', 'buyer@example.com')
            ->json('token');

        $this->fresh()->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.name', $user->name);
    }

    public function test_suspended_accounts_cannot_sign_in_or_keep_using_a_token(): void
    {
        $user = User::factory()->create(['email' => 'gone@example.com']);
        $token = $this->tokenFor($user);
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->postJson('/api/v1/auth/login', ['email' => 'gone@example.com', 'password' => 'password'])
            ->assertUnprocessable();

        $this->fresh()->withToken($token)->getJson('/api/v1/me')->assertForbidden();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_signing_out_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->fresh()->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_events_can_be_browsed_without_signing_in(): void
    {
        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->event->public_id)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/events/'.$this->event->slug)
            ->assertOk()
            ->assertJsonPath('data.title', $this->event->title)
            ->assertJsonPath('data.ticket_types.0.id', $this->regular->public_id);

        $this->getJson('/api/v1/categories')->assertOk();
    }

    public function test_drafts_are_not_visible_to_the_app(): void
    {
        $draft = Event::factory()->forOrganizer($this->organizer())->create();

        $this->getJson('/api/v1/events/'.$draft->slug)->assertNotFound();
    }

    public function test_buying_needs_a_confirmed_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->withToken($this->tokenFor($user))->postJson('/api/v1/checkouts', [
            'event' => $this->event->public_id,
            'items' => [$this->regular->public_id => 1],
        ])->assertForbidden();
    }

    public function test_a_buyer_can_buy_tickets_and_keep_them_in_the_app(): void
    {
        $buyer = User::factory()->create();
        $token = $this->tokenFor($buyer);

        $checkout = $this->withToken($token)->postJson('/api/v1/checkouts', [
            'event' => $this->event->public_id,
            'items' => [$this->regular->public_id => 2],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->json('data.id');

        $pay = $this->fresh()->withToken($token)->postJson("/api/v1/checkouts/{$checkout}/pay")
            ->assertOk()
            ->assertJsonPath('data.paid', false);

        // The sandbox screen opens in the phone's browser from a signed link.
        $url = (string) $pay->json('data.payment_url');
        $payment = Payment::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString('/sandbox/payments/'.$payment->provider_payment_id, $url);
        $this->assertStringContainsString('signature=', $url);

        $this->browser()->get($url)->assertOk();

        // The unsigned address still needs the buyer's website session.
        $this->get('/sandbox/payments/'.$payment->provider_payment_id)->assertNotFound();

        // Paying sends the buyer back to the app's return page.
        $this->post($url, ['outcome' => 'succeed'])->assertRedirectContains('/app/payment-return/'.$checkout);

        $this->fresh()->withToken($token)->getJson("/api/v1/checkouts/{$checkout}")
            ->assertOk()
            ->assertJsonPath('data.order.paid', true);

        $orders = $this->fresh()->withToken($token)->getJson('/api/v1/orders')->assertOk();
        $orders->assertJsonCount(1, 'data')
            ->assertJsonCount(2, 'data.0.tickets')
            ->assertJsonPath('data.0.event.title', $this->event->title);
        $this->assertIsString($orders->json('data.0.tickets.0.qr'));
    }

    public function test_the_return_page_confirms_the_payment(): void
    {
        $buyer = User::factory()->create();
        $token = $this->tokenFor($buyer);

        $checkout = $this->withToken($token)->postJson('/api/v1/checkouts', [
            'event' => $this->event->public_id,
            'items' => [$this->regular->public_id => 1],
        ])->json('data.id');
        $this->fresh()->withToken($token)->postJson("/api/v1/checkouts/{$checkout}/pay")->assertOk();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $gateway = app(PaymentGatewayManager::class)->driver('fake');
        $this->assertInstanceOf(FakePaymentGateway::class, $gateway);
        $gateway->simulate((string) $payment->provider_payment_id, PaymentStatus::Succeeded);

        $this->browser()->get(URL::signedRoute('app.payment-return', ['checkout' => $checkout]))
            ->assertOk()
            ->assertSee('Payment received')
            ->assertSee('tickora://checkout/'.$checkout);

        $this->assertSame(OrderStatus::Paid, Order::query()->firstOrFail()->status);

        $this->get('/app/payment-return/'.$checkout)->assertForbidden();
    }

    public function test_a_free_order_completes_without_a_payment_page(): void
    {
        $free = TicketType::factory()->create(['event_id' => $this->event->id, 'unit_amount_minor' => 0, 'sort_order' => 1]);
        $buyer = User::factory()->create();
        $token = $this->tokenFor($buyer);

        $checkout = $this->withToken($token)->postJson('/api/v1/checkouts', [
            'event' => $this->event->public_id,
            'items' => [$free->public_id => 1],
        ])->assertCreated()->json('data.id');

        $this->fresh()->withToken($token)->postJson("/api/v1/checkouts/{$checkout}/pay")
            ->assertOk()
            ->assertJsonPath('data.paid', true)
            ->assertJsonPath('data.payment_url', null);
    }

    public function test_buyers_cannot_see_each_others_checkouts_or_orders(): void
    {
        $buyer = User::factory()->create();
        $checkout = $this->withToken($this->tokenFor($buyer))->postJson('/api/v1/checkouts', [
            'event' => $this->event->public_id,
            'items' => [$this->regular->public_id => 1],
        ])->json('data.id');

        $intruder = User::factory()->create();
        $token = $this->tokenFor($intruder);

        $this->fresh()->withToken($token)->getJson("/api/v1/checkouts/{$checkout}")->assertForbidden();
        $this->fresh()->withToken($token)->postJson("/api/v1/checkouts/{$checkout}/pay")->assertForbidden();
        $this->fresh()->withToken($token)->deleteJson("/api/v1/checkouts/{$checkout}")->assertForbidden();
        $this->fresh()->withToken($token)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_buyer_can_release_their_tickets(): void
    {
        $buyer = User::factory()->create();
        $token = $this->tokenFor($buyer);

        $checkout = $this->withToken($token)->postJson('/api/v1/checkouts', [
            'event' => $this->event->public_id,
            'items' => [$this->regular->public_id => 1],
        ])->json('data.id');

        $this->fresh()->withToken($token)->deleteJson("/api/v1/checkouts/{$checkout}")->assertOk();
        $this->fresh()->withToken($token)->getJson("/api/v1/checkouts/{$checkout}")
            ->assertJsonPath('data.status', 'cancelled');
    }
}
