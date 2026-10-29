<?php

namespace Tests\Feature\Organizer;

use App\Enums\OrganizerRole;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\TicketType;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class EventImagesTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
        Storage::fake('public');
        config(['tickora.media_disk' => 'public', 'tickora.image_max_edge' => 2400]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Afro-Jazz Sessions',
            'category' => 'music',
            'summary' => null,
            'description' => null,
            'timezone' => 'Indian/Mauritius',
            'currency' => 'MUR',
            'starts_at' => now()->addMonth()->format('Y-m-d').'T19:00',
            'ends_at' => now()->addMonth()->format('Y-m-d').'T23:00',
            'sales_starts_at' => null,
            'sales_ends_at' => null,
            'visibility' => 'public',
            'refund_policy' => null,
            'min_age' => null,
            'venue' => [
                'name' => 'Caudan Arts Centre',
                'address_line' => 'Le Caudan Waterfront',
                'city' => 'Port Louis',
                'country_code' => 'MU',
            ],
        ], $overrides);
    }

    private function photo(string $name = 'cover.jpg', int $width = 1600, int $height = 1000): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    private function urlFor(Event $event, string $suffix = ''): string
    {
        return '/organizer/events/'.$event->public_id.'/images'.$suffix;
    }

    public function test_photos_can_be_added_while_creating_an_event(): void
    {
        $organizer = $this->organizer();

        $this->actingAs($this->ownerOf($organizer))
            ->post('/organizer/events', $this->payload([
                'images' => [$this->photo('stage.jpg'), $this->photo('crowd.png')],
            ]))
            ->assertSessionHasNoErrors();

        $event = Event::query()->where('title', 'Afro-Jazz Sessions')->firstOrFail();
        $images = $event->images()->get();

        $this->assertCount(2, $images);
        $this->assertSame([0, 1], $images->pluck('sort_order')->all());
        $this->assertSame('image/jpeg', $images[0]->mime_type);
        $this->assertSame('image/png', $images[1]->mime_type);
        $this->assertStringStartsWith('events/'.$event->public_id.'/', $images[0]->path);
        $this->assertSame($images[0]->path, $event->cover_image_key);

        foreach ($images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_large_photos_are_scaled_down_when_stored(): void
    {
        $organizer = $this->organizer();
        $event = Event::factory()->forOrganizer($organizer)->create();

        $this->actingAs($this->ownerOf($organizer))
            ->post($this->urlFor($event), ['images' => [$this->photo('huge.jpg', 4000, 2500)]])
            ->assertSessionHasNoErrors();

        $image = $event->images()->firstOrFail();
        $this->assertSame(2400, $image->width);
        $this->assertSame(1500, $image->height);

        $stored = getimagesizefromstring((string) Storage::disk('public')->get($image->path));
        $this->assertNotFalse($stored);
        $this->assertSame(2400, $stored[0]);
    }

    public function test_a_failing_photo_saves_nothing(): void
    {
        $organizer = $this->organizer();

        $this->actingAs($this->ownerOf($organizer))
            ->post('/organizer/events', $this->payload([
                'images' => [$this->photo(), UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')],
            ]))
            ->assertSessionHasErrors('images.1');

        $this->assertSame(0, Event::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_unsafe_tiny_or_too_many_images_are_rejected(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $event = Event::factory()->forOrganizer($organizer)->create();

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($owner)->post($this->urlFor($event), ['images' => [$svg]])
            ->assertSessionHasErrors('images.0');

        $disguised = UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "hi"; ?>');
        $this->actingAs($owner)->post($this->urlFor($event), ['images' => [$disguised]])
            ->assertSessionHasErrors('images.0');

        $this->actingAs($owner)->post($this->urlFor($event), ['images' => [$this->photo('tiny.jpg', 200, 100)]])
            ->assertSessionHasErrors('images.0');

        config(['tickora.max_event_images' => 2]);
        $this->actingAs($owner)->post($this->urlFor($event), ['images' => [$this->photo(), $this->photo()]])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($this->urlFor($event), ['images' => [$this->photo()]])
            ->assertSessionHasErrors('images');

        $this->assertSame(2, $event->images()->count());
    }

    public function test_cover_can_be_changed_and_removing_the_cover_promotes_the_next(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $event = Event::factory()->forOrganizer($organizer)->create();

        $this->actingAs($owner)->post($this->urlFor($event), [
            'images' => [$this->photo('a.jpg'), $this->photo('b.jpg'), $this->photo('c.jpg')],
        ]);
        [$a, $b, $c] = $event->images()->get()->all();

        $this->actingAs($owner)->post($this->urlFor($event, "/{$c->public_id}/cover"))
            ->assertRedirect('/organizer/events/'.$event->public_id);

        $this->assertSame([$c->id, $a->id, $b->id], $event->images()->pluck('id')->all());
        $this->assertSame($c->path, $event->fresh()?->cover_image_key);

        $this->actingAs($owner)->delete($this->urlFor($event, "/{$c->public_id}"));

        Storage::disk('public')->assertMissing($c->path);
        $this->assertSame($a->path, $event->fresh()?->cover_image_key);

        $this->actingAs($owner)->delete($this->urlFor($event, "/{$a->public_id}"));
        $this->actingAs($owner)->delete($this->urlFor($event, "/{$b->public_id}"));
        $this->assertNull($event->fresh()?->cover_image_key);
    }

    public function test_images_are_isolated_between_organisers_and_staff_cannot_edit(): void
    {
        $organizer = $this->organizer();
        $event = Event::factory()->forOrganizer($organizer)->create();
        $this->actingAs($this->ownerOf($organizer))->post($this->urlFor($event), ['images' => [$this->photo()]]);
        $image = $event->images()->firstOrFail();

        $outsider = $this->ownerOf($this->organizer());
        $this->actingAs($outsider)->post($this->urlFor($event), ['images' => [$this->photo()]])->assertNotFound();
        $this->actingAs($outsider)->delete($this->urlFor($event, "/{$image->public_id}"))->assertNotFound();

        // An image id from another event does not resolve under this one.
        $otherEvent = Event::factory()->forOrganizer($organizer)->create();
        $this->actingAs($this->ownerOf($organizer))
            ->delete($this->urlFor($otherEvent, "/{$image->public_id}"))
            ->assertNotFound();

        $staff = $this->memberOf($organizer, OrganizerRole::Staff);
        $this->assignStaff($event, $staff);
        $this->actingAs($staff)->post($this->urlFor($event), ['images' => [$this->photo()]])->assertForbidden();

        $this->assertSame(1, EventImage::query()->count());
    }

    public function test_deleting_a_draft_deletes_its_files(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $event = Event::factory()->forOrganizer($organizer)->create();
        $this->actingAs($owner)->post($this->urlFor($event), ['images' => [$this->photo()]]);
        $path = $event->images()->firstOrFail()->path;

        $this->actingAs($owner)->delete('/organizer/events/'.$event->public_id)->assertRedirect();

        Storage::disk('public')->assertMissing($path);
        $this->assertSame(0, EventImage::query()->count());
    }

    public function test_covers_and_galleries_appear_on_public_pages(): void
    {
        $organizer = $this->organizer();
        $event = Event::factory()->forOrganizer($organizer)->published()->create();
        TicketType::factory()->create(['event_id' => $event->id]);
        $this->actingAs($this->ownerOf($organizer))->post($this->urlFor($event), [
            'images' => [$this->photo('a.jpg'), $this->photo('b.jpg')],
        ]);
        auth()->logout();

        $cover = $event->images()->firstOrFail();

        $this->get('/events')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.data.0.cover_url', Storage::disk('public')->url($cover->path)));

        $this->get('/events/'.$event->slug)
            ->assertInertia(fn (Assert $page) => $page
                ->has('event.images', 2)
                ->where('event.images.0.id', $cover->public_id)
                ->where('event.images.0.width', 1600)
                ->missing('event.images.0.path'));
    }

    public function test_dates_with_seconds_or_a_space_are_accepted(): void
    {
        $organizer = $this->organizer();
        $day = now()->addMonth()->format('Y-m-d');

        $this->actingAs($this->ownerOf($organizer))
            ->post('/organizer/events', $this->payload([
                'starts_at' => $day.'T19:00:00',
                'ends_at' => $day.' 23:00',
                'sales_ends_at' => $day.'T18:00:00.000',
            ]))
            ->assertSessionHasNoErrors();

        $event = Event::query()->firstOrFail();
        $this->assertSame('15:00', $event->starts_at->utc()->format('H:i'));
        $this->assertSame('19:00', $event->ends_at->utc()->format('H:i'));
        $this->assertSame('14:00', $event->sales_ends_at?->utc()->format('H:i'));
    }

    public function test_an_unreadable_date_gets_a_clear_message(): void
    {
        $this->actingAs($this->ownerOf($this->organizer()))
            ->post('/organizer/events', $this->payload(['starts_at' => '05/11/2026 7pm']))
            ->assertSessionHasErrors(['starts_at' => 'Choose a valid date and time for the start time.']);
    }
}
