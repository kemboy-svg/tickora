<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create a draft event, or update one.
 *
 * Drafts can change anything. Once an event has been submitted or
 * published, its schedule, venue and currency are locked — buyers rely on
 * them — and only descriptive details can change. Rescheduling a live event
 * is a separate, customer-notifying flow.
 */
class SaveEvent
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(User $actor, Organizer $organizer, EventData $data): Event
    {
        Gate::forUser($actor)->authorize('createEvent', $organizer);

        $currency = $this->assertCurrency($data->currency);

        return DB::transaction(function () use ($actor, $organizer, $data, $currency): Event {
            $event = new Event;
            $event->organizer_id = $organizer->id;
            $event->slug = $this->uniqueSlug($data->title);
            $event->currency = $currency;
            $this->fillSchedule($event, $organizer, $data);
            $this->fillDetails($event, $data);
            $event->save();

            $this->audit->record('event.created', $event, [
                'title' => $event->title,
                'starts_at' => $event->starts_at->toIso8601String(),
            ], actor: $actor);

            return $event;
        });
    }

    public function update(User $actor, Event $event, EventData $data): Event
    {
        Gate::forUser($actor)->authorize('update', $event);

        return DB::transaction(function () use ($actor, $event, $data): Event {
            $event->load('organizer');

            if ($event->status === EventStatus::Draft) {
                if ($data->currency !== $event->currency) {
                    if ($event->ticketTypes()->exists()) {
                        throw ValidationException::withMessages([
                            'currency' => 'Remove the ticket types before changing the event currency.',
                        ]);
                    }

                    $event->currency = $this->assertCurrency($data->currency);
                }

                $this->fillSchedule($event, $event->organizer, $data);
            }

            $this->fillDetails($event, $data);

            $changes = array_keys($event->getDirty());
            $event->save();

            if ($changes !== []) {
                $this->audit->record('event.updated', $event, ['changed' => $changes], actor: $actor);
            }

            return $event;
        });
    }

    private function fillSchedule(Event $event, Organizer $organizer, EventData $data): void
    {
        $event->timezone = $data->timezone;
        $event->starts_at = $data->startsAt;
        $event->ends_at = $data->endsAt;
        $event->sales_starts_at = $data->salesStartsAt;
        $event->sales_ends_at = $data->salesEndsAt;
        $event->venue_id = $this->venueFor($organizer, $data)->id;
    }

    private function fillDetails(Event $event, EventData $data): void
    {
        $event->title = $data->title;
        $event->category_id = $data->categoryId;
        $event->summary = $data->summary;
        $event->description = $data->description;
        $event->visibility = $data->visibility;
        $event->refund_policy = $data->refundPolicy;
        $event->min_age = $data->minAge;
    }

    private function venueFor(Organizer $organizer, EventData $data): Venue
    {
        // Reuse the organiser's existing venue record when the same place is
        // entered again, so venues accumulate into a reusable list.
        $venue = Venue::query()
            ->where('organizer_id', $organizer->id)
            ->where('name', $data->venue['name'])
            ->where('address_line', $data->venue['address_line'])
            ->where('city', $data->venue['city'])
            ->first() ?? new Venue;

        $venue->name = $data->venue['name'];
        $venue->address_line = $data->venue['address_line'];
        $venue->city = $data->venue['city'];
        $venue->country_code = strtoupper($data->venue['country_code']);
        $venue->region = $data->venue['region'];
        $venue->postal_code = $data->venue['postal_code'];
        $venue->timezone = $data->timezone;
        $venue->organizer_id = $organizer->id;
        $venue->save();

        return $venue;
    }

    private function assertCurrency(string $currency): string
    {
        if (! Currency::isAllowed($currency)) {
            throw ValidationException::withMessages([
                'currency' => "Events cannot be priced in {$currency} on Tickora yet.",
            ]);
        }

        return Currency::normalise($currency);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 80, '') ?: 'event';

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (Event::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
