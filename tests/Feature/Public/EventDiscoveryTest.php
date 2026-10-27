<?php

namespace Tests\Feature\Public;

use App\Enums\TicketTypeStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\TicketType;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class EventDiscoveryTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private function live(array $attributes = [], int $price = 85000): Event
    {
        $event = Event::factory()->published()->create($attributes);
        TicketType::factory()->create(['event_id' => $event->id, 'unit_amount_minor' => $price]);

        return $event;
    }

    public function test_only_published_public_upcoming_events_are_listed(): void
    {
        $listed = $this->live(['title' => 'Listed Show']);
        Event::factory()->create(['title' => 'Draft Show']);
        Event::factory()->pendingReview()->create(['title' => 'Review Show']);
        Event::factory()->cancelled()->create(['title' => 'Cancelled Show']);
        $this->live(['title' => 'Secret Show', 'visibility' => 'unlisted']);
        $this->live([
            'title' => 'Past Show',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDays(3)->addHours(3),
            'sales_ends_at' => null,
        ]);

        $this->get('/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/events/Index')
                ->has('events.data', 1)
                ->where('events.data.0.id', $listed->public_id)
                ->where('events.data.0.price_from.formatted', 'MUR 850.00'));
    }

    public function test_search_category_city_and_price_filters(): void
    {
        $music = Category::factory()->create(['slug' => 'music', 'name' => 'Music']);
        $business = Category::factory()->create(['slug' => 'business', 'name' => 'Business']);
        $organizer = Organizer::factory()->create();
        $nairobi = Venue::factory()->create(['organizer_id' => $organizer->id, 'city' => 'Nairobi']);

        $jazz = $this->live(['title' => 'Afro-Jazz Sessions', 'category_id' => $music->id, 'organizer_id' => $organizer->id, 'venue_id' => $nairobi->id]);
        $summit = $this->live(['title' => 'Tech Summit', 'category_id' => $business->id]);
        $free = $this->live(['title' => 'Community Run', 'category_id' => $business->id], price: 0);

        $ids = fn (array $query) => collect($this->get('/events?'.http_build_query($query))->viewData('page')['props']['events']['data'])->pluck('id')->sort()->values()->all();

        $this->assertSame([$jazz->public_id], $ids(['q' => 'jazz']));
        $this->assertSame([$jazz->public_id], $ids(['category' => 'music']));
        $this->assertSame([$jazz->public_id], $ids(['city' => 'nairobi']));
        $this->assertSame([$free->public_id], $ids(['price' => 'free']));
        $this->assertEqualsCanonicalizing([$jazz->public_id, $summit->public_id], $ids(['price' => 'paid']));
    }

    public function test_sorting_by_price(): void
    {
        $cheap = $this->live(['title' => 'Cheap'], price: 10000);
        $dear = $this->live(['title' => 'Dear'], price: 900000);

        $this->get('/events?sort=price_desc')
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.id', $dear->public_id));

        $this->get('/events?sort=price_asc')
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.id', $cheap->public_id));
    }

    public function test_search_input_cannot_inject_like_wildcards(): void
    {
        $this->live(['title' => 'Some Event']);

        $this->get('/events?q=%25')
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 0));
    }

    public function test_the_event_page_shows_public_ticket_types_only(): void
    {
        $event = Event::factory()->published()->create();
        TicketType::factory()->create(['event_id' => $event->id, 'name' => 'Regular', 'sort_order' => 1]);
        TicketType::factory()->create(['event_id' => $event->id, 'name' => 'Comp', 'status' => TicketTypeStatus::Hidden]);

        $this->get('/events/'.$event->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/events/Show')
                ->where('event.title', $event->title)
                ->has('event.ticket_types', 1)
                ->where('event.ticket_types.0.name', 'Regular')
                ->where('event.sales_open', true)
                ->missing('event.ticket_types.0.quantity_total'));
    }

    public function test_unlisted_events_are_reachable_by_link_but_marked_noindex(): void
    {
        $event = $this->live(['visibility' => 'unlisted']);

        $this->get('/events/'.$event->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('unlisted', true));
    }

    public function test_unpublished_events_are_not_found(): void
    {
        foreach ([Event::factory()->create(), Event::factory()->pendingReview()->create(), Event::factory()->cancelled()->create()] as $event) {
            $this->get('/events/'.$event->slug)->assertNotFound();
        }
    }

    public function test_the_homepage_lists_upcoming_events_and_categories(): void
    {
        $event = $this->live();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/Home')
                ->where('featured.0.id', $event->public_id)
                ->has('categories'));
    }

    public function test_organiser_pages_are_public_only_for_verified_organisers(): void
    {
        $verified = Organizer::factory()->create();
        $event = $this->live(['organizer_id' => $verified->id]);
        $pending = Organizer::factory()->pending()->create();

        $this->get('/organizers/'.$verified->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/organizers/Show')
                ->where('events.0.id', $event->public_id));

        $this->get('/organizers/'.$pending->slug)->assertNotFound();
    }
}
