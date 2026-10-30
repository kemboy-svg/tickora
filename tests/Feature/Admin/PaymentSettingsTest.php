<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentProvider;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\PaymentGatewaySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\FakesPaymentProviders;
use Tests\TestCase;

class PaymentSettingsTest extends TestCase
{
    use CreatesTenants;
    use FakesPaymentProviders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tickora.require_two_factor' => false]);
        $this->fakeProviderApis();
    }

    public function test_only_platform_admins_manage_payment_settings(): void
    {
        $this->actingAs($this->platformUser(PlatformRole::Finance))->get('/admin/payments')->assertForbidden();
        $this->actingAs($this->platformUser(PlatformRole::Support))
            ->put('/admin/payments/stripe', ['enabled' => true, 'secret_key' => self::STRIPE_SECRET])
            ->assertForbidden();
        $this->actingAs($this->ownerOf($this->organizer()))->get('/admin/payments')->assertForbidden();

        $this->actingAs($this->platformUser(PlatformRole::Admin))->get('/admin/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/payments/Index')
                ->has('providers', 2)
                ->where('providers.0.provider', 'stripe')
                ->where('providers.0.webhook_url', route('webhooks.payments', 'stripe')));
    }

    public function test_keys_are_stored_encrypted_and_never_sent_back(): void
    {
        $admin = $this->platformUser(PlatformRole::Admin);

        $this->actingAs($admin)->put('/admin/payments/stripe', [
            'enabled' => true,
            'public_key' => 'pk_test_51TickoraPublic',
            'secret_key' => self::STRIPE_SECRET,
            'webhook_secret' => self::STRIPE_WEBHOOK_SECRET,
        ])->assertSessionHasNoErrors()->assertRedirect('/admin/payments');

        $raw = DB::table('payment_gateways')->where('provider', 'stripe')->first();
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(self::STRIPE_SECRET, (string) $raw->secret_key);
        $this->assertStringNotContainsString(self::STRIPE_WEBHOOK_SECRET, (string) $raw->webhook_secret);

        $setting = PaymentGatewaySetting::for(PaymentProvider::Stripe);
        $this->assertSame(self::STRIPE_SECRET, $setting?->secret_key);
        $this->assertTrue($setting->isUsable());
        $this->assertSame('test', $setting->mode());

        $page = $this->actingAs($admin)->get('/admin/payments');
        $page->assertInertia(fn (Assert $p) => $p
            ->where('providers.0.secret_key_hint', '••••1234')
            ->where('providers.0.enabled', true)
            ->where('providers.0.mode', 'test'));
        $this->assertStringNotContainsString(self::STRIPE_SECRET, (string) $page->getContent());
        $this->assertStringNotContainsString(self::STRIPE_WEBHOOK_SECRET, (string) $page->getContent());

        // The audit trail says what changed, never the values.
        $log = AuditLog::query()->where('action', 'payment_gateway.updated')->firstOrFail();
        $this->assertStringNotContainsString(self::STRIPE_SECRET, (string) json_encode($log->metadata));
    }

    public function test_blank_key_fields_keep_the_saved_keys(): void
    {
        $admin = $this->platformUser(PlatformRole::Admin);
        $this->configureProvider(PaymentProvider::Stripe);

        $this->actingAs($admin)->put('/admin/payments/stripe', [
            'enabled' => false,
            'secret_key' => '',
            'webhook_secret' => '',
        ])->assertSessionHasNoErrors();

        $setting = PaymentGatewaySetting::for(PaymentProvider::Stripe);
        $this->assertSame(self::STRIPE_SECRET, $setting?->secret_key);
        $this->assertSame(self::STRIPE_WEBHOOK_SECRET, $setting->webhook_secret);
        $this->assertFalse($setting->enabled);
    }

    public function test_keys_are_checked_before_saving(): void
    {
        $admin = $this->platformUser(PlatformRole::Admin);

        $this->actingAs($admin)->put('/admin/payments/stripe', ['enabled' => true])
            ->assertSessionHasErrors('secret_key');

        $this->actingAs($admin)->put('/admin/payments/stripe', ['enabled' => true, 'secret_key' => 'not-a-key'])
            ->assertSessionHasErrors('secret_key');

        $this->actingAs($admin)->put('/admin/payments/paystack', [
            'enabled' => true,
            'secret_key' => 'sk_live_paystackLive1',
            'public_key' => 'pk_test_paystackTest1',
        ])->assertSessionHasErrors('public_key');

        $this->actingAs($admin)->put('/admin/payments/stripe', [
            'enabled' => true, 'secret_key' => self::STRIPE_SECRET, 'webhook_secret' => 'nope',
        ])->assertSessionHasErrors('webhook_secret');

        $this->assertSame(0, PaymentGatewaySetting::query()->count());
    }

    public function test_connection_can_be_tested(): void
    {
        $admin = $this->platformUser(PlatformRole::Admin);
        $this->configureProvider(PaymentProvider::Stripe);

        $this->actingAs($admin)->post('/admin/payments/stripe/test')
            ->assertRedirect('/admin/payments');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.stripe.com/v1/balance'
            && $r->hasHeader('Authorization', 'Bearer '.self::STRIPE_SECRET));
    }

    public function test_the_default_must_be_ready(): void
    {
        $admin = $this->platformUser(PlatformRole::Admin);

        $this->actingAs($admin)->put('/admin/payments/default', ['provider' => 'paystack'])
            ->assertSessionHasErrors('provider');

        $this->configureProvider(PaymentProvider::Paystack);
        $this->configureProvider(PaymentProvider::Stripe, default: true);

        $this->actingAs($admin)->put('/admin/payments/default', ['provider' => 'paystack'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(PaymentGatewaySetting::for(PaymentProvider::Paystack)?->is_default);
        $this->assertFalse(PaymentGatewaySetting::for(PaymentProvider::Stripe)?->is_default);
    }

    public function test_admins_choose_the_provider_for_each_event(): void
    {
        $admin = $this->platformUser(PlatformRole::Admin);
        $event = $this->publishedEventFor($this->organizer()); // MUR

        $this->actingAs($admin)->patch("/admin/events/{$event->public_id}/payment-provider", ['provider' => 'stripe'])
            ->assertSessionHasErrors('provider'); // not set up yet

        $this->configureProvider(PaymentProvider::Stripe);
        $this->configureProvider(PaymentProvider::Paystack);

        $this->actingAs($admin)->patch("/admin/events/{$event->public_id}/payment-provider", ['provider' => 'paystack'])
            ->assertSessionHasErrors('provider'); // Paystack can't charge MUR

        $this->actingAs($admin)->patch("/admin/events/{$event->public_id}/payment-provider", ['provider' => 'stripe'])
            ->assertSessionHasNoErrors();
        $this->assertSame(PaymentProvider::Stripe, $event->fresh()?->payment_provider);
        $this->assertTrue(AuditLog::query()->where('action', 'event.payment_provider_changed')->exists());

        $this->actingAs($admin)->patch("/admin/events/{$event->public_id}/payment-provider", ['provider' => null])
            ->assertSessionHasNoErrors();
        $this->assertNull($event->fresh()?->payment_provider);

        // Moderators without the settings permission can't change it.
        $this->actingAs($this->platformUser(PlatformRole::Support))
            ->patch("/admin/events/{$event->public_id}/payment-provider", ['provider' => 'stripe'])
            ->assertForbidden();
    }

    public function test_live_events_are_listed_with_their_provider(): void
    {
        $event = $this->publishedEventFor($this->organizer());
        $event->forceFill(['payment_provider' => PaymentProvider::Stripe])->save();
        Event::factory()->pendingReview()->create();

        $this->actingAs($this->platformUser(PlatformRole::Admin))->get('/admin/events?status=published')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'published')
                ->has('events.data', 1)
                ->where('events.data.0.payment_provider', 'stripe')
                ->where('payments.can_manage', true));
    }

    public function test_organisers_cannot_set_the_provider_through_the_event_form(): void
    {
        $organizer = $this->organizer();
        $event = Event::factory()->forOrganizer($organizer)->create();

        $this->actingAs($this->ownerOf($organizer))->put("/organizer/events/{$event->public_id}", [
            'title' => $event->title,
            'category' => $event->category->slug,
            'timezone' => $event->timezone,
            'currency' => $event->currency,
            'starts_at' => $event->starts_at->setTimezone($event->timezone)->format('Y-m-d\\TH:i'),
            'ends_at' => $event->ends_at->setTimezone($event->timezone)->format('Y-m-d\\TH:i'),
            'visibility' => 'public',
            'payment_provider' => 'stripe',
            'venue' => ['name' => 'Hall', 'address_line' => '1 Road', 'city' => 'Port Louis', 'country_code' => 'MU'],
        ]);

        $this->assertNull($event->fresh()?->payment_provider);
    }
}
