<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(implode(' ', fake()->unique()->words(3)));
        $startsAt = now()->addDays(fake()->numberBetween(7, 60))->setTime(15, 0);

        return [
            'organizer_id' => Organizer::factory(),
            'category_id' => Category::factory(),
            'venue_id' => fn (array $attributes) => Venue::factory()->state(['organizer_id' => $attributes['organizer_id']]),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'summary' => fake()->sentence(),
            'description' => fake()->paragraphs(2, true),
            'timezone' => 'Indian/Mauritius',
            'currency' => 'MUR',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(4),
            'sales_starts_at' => now()->subDay(),
            'sales_ends_at' => $startsAt->copy()->subHours(2),
            'status' => EventStatus::Draft,
            'visibility' => EventVisibility::Public,
            'refund_policy' => 'Refunds up to 7 days before the event, excluding the booking fee.',
        ];
    }

    public function forOrganizer(Organizer $organizer): static
    {
        return $this->state(fn (): array => [
            'organizer_id' => $organizer->id,
            'currency' => $organizer->default_currency,
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (): array => [
            'status' => EventStatus::PendingReview,
            'submitted_at' => now(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => EventStatus::Published,
            'submitted_at' => now()->subDays(2),
            'published_at' => now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => EventStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Venue unavailable.',
        ]);
    }

    public function unlisted(): static
    {
        return $this->state(fn (): array => ['visibility' => EventVisibility::Unlisted]);
    }
}
