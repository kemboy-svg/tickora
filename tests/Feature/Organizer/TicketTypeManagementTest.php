<?php

namespace Tests\Feature\Organizer;

use App\Enums\OrganizerRole;
use App\Enums\TicketTypeStatus;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TicketTypeManagementTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'VIP',
            'description' => 'Front-of-stage deck and a welcome drink.',
            'price' => '2400.00',
            'quantity_total' => 120,
            'min_per_order' => 1,
            'max_per_order' => 6,
            'sales_starts_at' => null,
            'sales_ends_at' => null,
            'status' => 'active',
        ], $overrides);
    }

    private function url(Event $event, ?TicketType $type = null): string
    {
        return '/organizer/events/'.$event->public_id.'/ticket-types'.($type ? '/'.$type->public_id : '');
    }

    public function test_a_manager_adds_a_ticket_type_priced_in_the_event_currency(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $manager = $this->memberOf($organizer, OrganizerRole::Manager);

        $this->actingAs($manager)
            ->post($this->url($event), $this->payload())
            ->assertRedirect('/organizer/events/'.$event->public_id);

        $type = TicketType::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(240000, $type->unit_amount_minor);
        $this->assertSame($event->currency, $type->currency);
        $this->assertSame(TicketTypeStatus::Active, $type->status);
    }

    public function test_free_tickets_are_allowed(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);

        $this->actingAs($this->ownerOf($organizer))
            ->post($this->url($event), $this->payload(['name' => 'Community', 'price' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(TicketType::query()->where('name', 'Community')->firstOrFail()->isFree());
    }

    public function test_ticket_type_validation(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $owner = $this->ownerOf($organizer);

        $this->actingAs($owner)
            ->post($this->url($event), $this->payload([
                'name' => '',
                'price' => '12.345',
                'quantity_total' => 0,
                'min_per_order' => 5,
                'max_per_order' => 2,
                'status' => 'sold',
            ]))
            ->assertSessionHasErrors(['name', 'price', 'quantity_total', 'max_per_order', 'status']);

        $this->actingAs($owner)
            ->post($this->url($event), $this->payload(['price' => '-5']))
            ->assertSessionHasErrors(['price']);
    }

    public function test_names_are_unique_per_event(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        TicketType::factory()->create(['event_id' => $event->id, 'name' => 'VIP']);

        $this->actingAs($this->ownerOf($organizer))
            ->post($this->url($event), $this->payload(['name' => 'vip']))
            ->assertSessionHasErrors(['name']);
    }

    public function test_sales_cannot_end_after_the_event(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $afterEnd = $event->ends_at->copy()->setTimezone($event->timezone)->addDay()->format('Y-m-d\TH:i');

        $this->actingAs($this->ownerOf($organizer))
            ->post($this->url($event), $this->payload(['sales_ends_at' => $afterEnd]))
            ->assertSessionHasErrors(['sales_ends_at']);
    }

    public function test_sold_ticket_types_keep_their_price_and_capacity_floor(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $owner = $this->ownerOf($organizer);
        $type = TicketType::factory()->create([
            'event_id' => $event->id,
            'name' => 'VIP',
            'unit_amount_minor' => 240000,
            'quantity_total' => 120,
            'quantity_sold' => 50,
        ]);

        $this->actingAs($owner)
            ->put($this->url($event, $type), $this->payload(['price' => '2000']))
            ->assertSessionHasErrors(['price']);

        $this->actingAs($owner)
            ->put($this->url($event, $type), $this->payload(['quantity_total' => 40]))
            ->assertSessionHasErrors(['quantity_total']);

        $this->actingAs($owner)
            ->put($this->url($event, $type), $this->payload(['quantity_total' => 200, 'status' => 'paused']))
            ->assertSessionHasNoErrors();

        $type->refresh();
        $this->assertSame(200, $type->quantity_total);
        $this->assertSame(TicketTypeStatus::Paused, $type->status);
        $this->assertSame(240000, $type->unit_amount_minor);
    }

    public function test_only_unsold_ticket_types_can_be_deleted(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $owner = $this->ownerOf($organizer);
        $unsold = TicketType::factory()->create(['event_id' => $event->id, 'name' => 'Early Bird']);
        $sold = TicketType::factory()->create(['event_id' => $event->id, 'name' => 'Regular', 'quantity_sold' => 3]);

        $this->actingAs($owner)->delete($this->url($event, $sold))->assertSessionHasErrors(['ticket_type']);
        $this->actingAs($owner)->delete($this->url($event, $unsold))->assertSessionHasNoErrors();

        $this->assertNull(TicketType::query()->find($unsold->id));
        $this->assertNotNull(TicketType::query()->find($sold->id));
    }

    public function test_ticket_types_of_another_event_are_not_found(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $eventA = $this->publishedEventFor($organizer);
        $eventB = $this->publishedEventFor($organizer);
        $typeOfB = TicketType::factory()->create(['event_id' => $eventB->id]);

        $this->actingAs($owner)
            ->put($this->url($eventA, $typeOfB), $this->payload())
            ->assertNotFound();
    }

    public function test_gate_staff_cannot_manage_ticket_types(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);
        $this->assignStaff($event, $staff);

        $this->actingAs($staff)->post($this->url($event), $this->payload())->assertForbidden();
    }
}
