<?php

namespace Tests\Feature\Organizer;

use App\Enums\EventStatus;
use App\Enums\OrganizerRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Event;
use App\Models\TicketType;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'Sega Nights: Live on the Waterfront',
            'category' => 'music',
            'summary' => 'Live sega by the harbour.',
            'description' => 'An open-air evening of live sega.',
            'timezone' => 'Indian/Mauritius',
            'currency' => 'MUR',
            'starts_at' => now()->addMonth()->format('Y-m-d').'T19:00',
            'ends_at' => now()->addMonth()->format('Y-m-d').'T23:30',
            'sales_starts_at' => null,
            'sales_ends_at' => null,
            'visibility' => 'public',
            'refund_policy' => 'Refunds up to 7 days before.',
            'min_age' => 16,
            'venue' => [
                'name' => 'Caudan Waterfront Amphitheatre',
                'address_line' => 'Le Caudan Waterfront',
                'city' => 'Port Louis',
                'country_code' => 'mu',
            ],
        ], $overrides);
    }

    public function test_an_owner_creates_a_draft_and_times_are_stored_in_utc(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);

        $response = $this->actingAs($owner)->post('/organizer/events', $this->payload());

        $event = Event::query()->where('title', 'Sega Nights: Live on the Waterfront')->firstOrFail();
        $response->assertRedirect('/organizer/events/'.$event->public_id);

        $this->assertSame(EventStatus::Draft, $event->status);
        $this->assertSame($organizer->id, $event->organizer_id);
        // 19:00 in Mauritius (UTC+4) is 15:00 UTC.
        $this->assertSame('15:00', $event->starts_at->utc()->format('H:i'));
        $this->assertSame('Indian/Mauritius', $event->timezone);
        $this->assertSame('Port Louis', $event->venue?->city);
        $this->assertSame('MU', $event->venue?->country_code);
        $this->assertTrue(AuditLog::query()->where('action', 'event.created')->exists());
    }

    public function test_event_validation(): void
    {
        $owner = $this->ownerOf($this->organizer());

        $this->actingAs($owner)
            ->post('/organizer/events', $this->payload([
                'title' => '',
                'category' => 'not-a-category',
                'currency' => 'GBP',
                'timezone' => 'Mars/Olympus',
                'venue' => ['city' => ''],
            ]))
            ->assertSessionHasErrors(['title', 'category', 'currency', 'timezone', 'venue.city']);

        $this->actingAs($owner)
            ->post('/organizer/events', $this->payload([
                'ends_at' => now()->addMonth()->format('Y-m-d').'T18:00',
            ]))
            ->assertSessionHasErrors(['ends_at']);

        $this->assertSame(0, Event::query()->count());
    }

    public function test_gate_staff_cannot_create_events(): void
    {
        $organizer = $this->organizer();
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);

        $this->actingAs($staff)->get('/organizer/events/create')->assertForbidden();
        $this->actingAs($staff)->post('/organizer/events', $this->payload())->assertForbidden();
    }

    public function test_another_organisers_event_is_not_found(): void
    {
        $owner = $this->ownerOf($this->organizer());
        $foreign = $this->publishedEventFor($this->organizer());

        $this->actingAs($owner)->get('/organizer/events/'.$foreign->public_id)->assertNotFound();
        $this->actingAs($owner)->put('/organizer/events/'.$foreign->public_id, $this->payload())->assertNotFound();
        $this->actingAs($owner)->post('/organizer/events/'.$foreign->public_id.'/cancel', ['reason' => 'Because I can'])->assertNotFound();
        $this->actingAs($owner)->delete('/organizer/events/'.$foreign->public_id)->assertNotFound();
    }

    public function test_the_event_list_only_shows_this_organisers_events(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $mine = $this->publishedEventFor($organizer);
        $this->publishedEventFor($this->organizer());

        $this->actingAs($owner)
            ->get('/organizer/events')
            ->assertInertia(fn (Assert $page) => $page
                ->component('organizer/events/Index')
                ->has('events.data', 1)
                ->where('events.data.0.id', $mine->public_id));
    }

    public function test_the_edit_page_shows_form_values_in_the_events_timezone(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $this->actingAs($owner)->post('/organizer/events', $this->payload());
        $event = Event::query()->firstOrFail();

        $this->actingAs($owner)
            ->get('/organizer/events/'.$event->public_id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organizer/events/Edit')
                ->where('event.starts_at', now()->addMonth()->format('Y-m-d').'T19:00')
                ->where('event.is_draft', true)
                ->where('can.submit', true)
                ->has('readiness', 1));
    }

    public function test_published_events_keep_their_schedule_but_details_can_change(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $event = $this->publishedEventFor($organizer);
        $event->load('venue');
        $originalStart = $event->starts_at->toIso8601String();

        $this->actingAs($owner)
            ->put('/organizer/events/'.$event->public_id, $this->payload([
                'title' => 'New title',
                'currency' => 'KES',
                'starts_at' => now()->addYear()->format('Y-m-d').'T10:00',
                'ends_at' => now()->addYear()->format('Y-m-d').'T12:00',
            ]))
            ->assertRedirect();

        $event->refresh();
        $this->assertSame('New title', $event->title);
        $this->assertSame($originalStart, $event->starts_at->toIso8601String());
        $this->assertSame('MUR', $event->currency);
    }

    public function test_the_currency_is_locked_once_ticket_types_exist(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $event = Event::factory()->forOrganizer($organizer)->create(['category_id' => Category::query()->firstOrFail()->id]);
        TicketType::factory()->create(['event_id' => $event->id]);

        $this->actingAs($owner)
            ->put('/organizer/events/'.$event->public_id, $this->payload(['currency' => 'KES']))
            ->assertSessionHasErrors(['currency']);
    }

    public function test_drafts_without_sales_can_be_deleted(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $draft = Event::factory()->forOrganizer($organizer)->create();

        $this->actingAs($owner)
            ->delete('/organizer/events/'.$draft->public_id)
            ->assertRedirect('/organizer/events');

        $this->assertNull(Event::query()->find($draft->id));
        $this->assertTrue(AuditLog::query()->where('action', 'event.deleted')->exists());
    }

    public function test_published_events_are_cancelled_not_deleted(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $event = $this->publishedEventFor($organizer);

        $this->actingAs($owner)->delete('/organizer/events/'.$event->public_id)->assertForbidden();

        $this->actingAs($owner)
            ->post('/organizer/events/'.$event->public_id.'/cancel', ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($owner)
            ->post('/organizer/events/'.$event->public_id.'/cancel', ['reason' => 'The venue flooded.'])
            ->assertRedirect();

        $event->refresh();
        $this->assertSame(EventStatus::Cancelled, $event->status);
        $this->assertSame('The venue flooded.', $event->cancellation_reason);
    }
}
