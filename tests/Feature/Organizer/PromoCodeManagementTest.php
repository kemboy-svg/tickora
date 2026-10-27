<?php

namespace Tests\Feature\Organizer;

use App\Enums\OrganizerRole;
use App\Models\AuditLog;
use App\Models\PromoCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PromoCodeManagementTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'code' => 'earlybird',
            'discount_type' => 'percent',
            'percent' => '15',
            'amount' => null,
            'event' => null,
            'starts_at' => null,
            'ends_at' => '2030-01-31T23:59',
            'timezone' => 'Indian/Mauritius',
            'max_redemptions' => 100,
            'max_per_customer' => 2,
        ], $overrides);
    }

    public function test_a_manager_creates_an_organiser_wide_percentage_code(): void
    {
        $organizer = $this->organizer();
        $manager = $this->memberOf($organizer, OrganizerRole::Manager);

        $this->actingAs($manager)
            ->post('/organizer/promo-codes', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/organizer/promo-codes');

        $promo = PromoCode::query()->forOrganizer($organizer)->firstOrFail();
        $this->assertSame('EARLYBIRD', $promo->code);
        $this->assertSame(1500, $promo->discount_value);
        $this->assertNull($promo->currency);
        $this->assertNull($promo->event_id);
        // 23:59 in Mauritius (UTC+4) is 19:59 UTC.
        $this->assertSame('2030-01-31 19:59', $promo->ends_at?->utc()->format('Y-m-d H:i'));
        $this->assertTrue(AuditLog::query()->where('action', 'promo_code.created')->exists());

        $this->actingAs($manager)->get('/organizer/promo-codes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organizer/promo-codes/Index')
                ->where('codes.0.code', 'EARLYBIRD')
                ->where('codes.0.discount_label', '15% off')
                ->missing('codes.0.id'));
    }

    public function test_a_fixed_code_for_one_event_uses_that_events_currency(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);

        $this->actingAs($this->ownerOf($organizer))
            ->post('/organizer/promo-codes', $this->payload([
                'code' => 'SAVE100',
                'discount_type' => 'fixed',
                'percent' => null,
                'amount' => '100',
                'event' => $event->public_id,
            ]))
            ->assertSessionHasNoErrors();

        $promo = PromoCode::query()->where('code', 'SAVE100')->firstOrFail();
        $this->assertSame($event->id, $promo->event_id);
        $this->assertSame('fixed', $promo->discount_type);
        $this->assertSame(10000, $promo->discount_value);
        $this->assertSame($event->currency, $promo->currency);
    }

    public function test_codes_are_unique_per_organiser_and_validated(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);

        $this->actingAs($owner)->post('/organizer/promo-codes', $this->payload());
        $this->actingAs($owner)->post('/organizer/promo-codes', $this->payload(['code' => 'EarlyBird']))
            ->assertSessionHasErrors('code');

        $this->actingAs($owner)->post('/organizer/promo-codes', $this->payload(['code' => 'bad code!']))
            ->assertSessionHasErrors('code');
        $this->actingAs($owner)->post('/organizer/promo-codes', $this->payload(['code' => 'BIG', 'percent' => '150']))
            ->assertSessionHasErrors('percent');
        $this->actingAs($owner)->post('/organizer/promo-codes', $this->payload(['code' => 'BACKWARDS', 'starts_at' => '2030-02-01T00:00']))
            ->assertSessionHasErrors('ends_at');

        // Another organiser may use the same code.
        $other = $this->organizer();
        $this->actingAs($this->ownerOf($other))->post('/organizer/promo-codes', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PromoCode::query()->count());
    }

    public function test_another_organisers_event_cannot_be_targeted(): void
    {
        $organizer = $this->organizer();
        $foreignEvent = $this->publishedEventFor($this->organizer());

        $this->actingAs($this->ownerOf($organizer))
            ->post('/organizer/promo-codes', $this->payload(['event' => $foreignEvent->public_id]))
            ->assertSessionHasErrors('event');

        $this->assertSame(0, PromoCode::query()->count());
    }

    public function test_staff_cannot_manage_promo_codes(): void
    {
        $organizer = $this->organizer();
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);

        $this->actingAs($staff)->get('/organizer/promo-codes')->assertForbidden();
        $this->actingAs($staff)->post('/organizer/promo-codes', $this->payload())->assertForbidden();
    }

    public function test_pause_and_delete_are_scoped_to_the_workspace(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $this->actingAs($owner)->post('/organizer/promo-codes', $this->payload());

        $other = $this->organizer();
        $this->actingAs($this->ownerOf($other))->put('/organizer/promo-codes/EARLYBIRD', ['is_active' => false])
            ->assertNotFound();

        $this->actingAs($owner)->put('/organizer/promo-codes/earlybird', ['is_active' => false])
            ->assertRedirect('/organizer/promo-codes');
        $this->assertFalse(PromoCode::query()->firstOrFail()->is_active);

        $this->actingAs($owner)->delete('/organizer/promo-codes/EARLYBIRD')
            ->assertRedirect('/organizer/promo-codes');
        $this->assertSame(0, PromoCode::query()->count());
    }

    public function test_used_codes_cannot_be_deleted(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $this->actingAs($owner)->post('/organizer/promo-codes', $this->payload());

        $promo = PromoCode::query()->firstOrFail();
        $promo->redemptions_count = 1;
        $promo->save();

        $this->actingAs($owner)->delete('/organizer/promo-codes/EARLYBIRD')
            ->assertSessionHasErrors('promo_code');

        $this->assertSame(1, PromoCode::query()->count());
    }
}
