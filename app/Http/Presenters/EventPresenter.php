<?php

namespace App\Http\Presenters;

use App\Enums\EventStatus;
use App\Enums\TicketTypeStatus;
use App\Models\Event;
use App\Models\TicketType;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Shapes events into the props the Vue pages receive. Only public IDs and
 * display-ready values leave the server — never internal IDs, hashes or
 * other organisers' data.
 *
 * Expects ticketTypes, venue, category and organizer to be eager loaded.
 */
final class EventPresenter
{
    /**
     * A listing card on the homepage and discovery page.
     *
     * @return array<string, mixed>
     */
    public static function card(Event $event): array
    {
        $onSale = self::publicTicketTypes($event);
        $from = $onSale->min('unit_amount_minor');

        return [
            'id' => $event->public_id,
            'slug' => $event->slug,
            'title' => $event->title,
            'summary' => $event->summary,
            'category' => $event->category->name,
            'category_slug' => $event->category->slug,
            'starts_at' => $event->starts_at->toIso8601String(),
            'starts_label' => LocalTime::display($event->starts_at, $event->timezone, 'D j M · H:i'),
            'venue' => $event->venue?->name,
            'city' => $event->venue?->city,
            'country_code' => $event->venue?->country_code,
            'price_from' => is_int($from) ? Money::ofMinor($from, $event->currency) : null,
            'is_free' => $from === 0,
            'availability' => self::availability($event),
        ];
    }

    /**
     * The public event page.
     *
     * @return array<string, mixed>
     */
    public static function detail(Event $event): array
    {
        $now = CarbonImmutable::now();

        return [
            ...self::card($event),
            'description' => $event->description,
            'timezone' => $event->timezone,
            'timezone_label' => LocalTime::offsetLabel($event->starts_at, $event->timezone),
            'date_label' => LocalTime::display($event->starts_at, $event->timezone, 'l j F Y'),
            'time_label' => LocalTime::display($event->starts_at, $event->timezone, 'H:i')
                .' – '.LocalTime::display($event->ends_at, $event->timezone, 'H:i'),
            'ends_at' => $event->ends_at->toIso8601String(),
            'sales_end_label' => LocalTime::display($event->sales_ends_at, $event->timezone),
            'sales_open' => self::salesOpen($event, $now),
            'refund_policy' => $event->refund_policy,
            'min_age' => $event->min_age,
            'status' => $event->status->value,
            'is_past' => $event->ends_at->isPast(),
            'venue_detail' => $event->venue === null ? null : [
                'name' => $event->venue->name,
                'address_line' => $event->venue->address_line,
                'city' => $event->venue->city,
                'region' => $event->venue->region,
                'country_code' => $event->venue->country_code,
            ],
            'organizer' => [
                'name' => $event->organizer->name,
                'slug' => $event->organizer->slug,
                'verified' => $event->organizer->canSell(),
            ],
            'ticket_types' => self::publicTicketTypes($event)
                ->map(fn (TicketType $type): array => [
                    'id' => $type->public_id,
                    'name' => $type->name,
                    'description' => $type->description,
                    'price' => $type->price,
                    'is_free' => $type->isFree(),
                    'remaining' => max(0, $type->quantity_total - $type->quantity_sold),
                    'sold_out' => $type->quantity_sold >= $type->quantity_total,
                    'on_sale' => $type->isOnSaleAt($now),
                    'sales_end_label' => LocalTime::display($type->sales_ends_at, $event->timezone),
                    'min_per_order' => $type->min_per_order,
                    'max_per_order' => $type->max_per_order,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * A row in the organiser's event list.
     *
     * @return array<string, mixed>
     */
    public static function organizerRow(Event $event): array
    {
        $sold = $event->ticketTypes->sum('quantity_sold');
        $capacity = $event->ticketTypes->sum('quantity_total');

        return [
            'id' => $event->public_id,
            'slug' => $event->slug,
            'title' => $event->title,
            'status' => $event->status->value,
            'visibility' => $event->visibility->value,
            'starts_label' => LocalTime::display($event->starts_at, $event->timezone),
            'venue' => $event->venue?->name,
            'city' => $event->venue?->city,
            'ticket_types' => $event->ticketTypes->count(),
            'sold' => $sold,
            'capacity' => $capacity,
            'review_notes' => $event->review_notes,
        ];
    }

    /**
     * Values for the organiser's edit form, times in the event's zone.
     *
     * @return array<string, mixed>
     */
    public static function form(Event $event): array
    {
        return [
            'id' => $event->public_id,
            'slug' => $event->slug,
            'status' => $event->status->value,
            'is_draft' => $event->status === EventStatus::Draft,
            'title' => $event->title,
            'category' => $event->category->slug,
            'summary' => $event->summary,
            'description' => $event->description,
            'timezone' => $event->timezone,
            'currency' => $event->currency,
            'starts_at' => LocalTime::toInput($event->starts_at, $event->timezone),
            'ends_at' => LocalTime::toInput($event->ends_at, $event->timezone),
            'sales_starts_at' => LocalTime::toInput($event->sales_starts_at, $event->timezone),
            'sales_ends_at' => LocalTime::toInput($event->sales_ends_at, $event->timezone),
            'visibility' => $event->visibility->value,
            'refund_policy' => $event->refund_policy,
            'min_age' => $event->min_age,
            'review_notes' => $event->review_notes,
            'cancellation_reason' => $event->cancellation_reason,
            'venue' => [
                'name' => $event->venue->name ?? '',
                'address_line' => $event->venue->address_line ?? '',
                'city' => $event->venue->city ?? '',
                'region' => $event->venue?->region,
                'postal_code' => $event->venue?->postal_code,
                'country_code' => $event->venue->country_code ?? $event->organizer->country_code,
            ],
            'ticket_types' => $event->ticketTypes
                ->map(fn (TicketType $type): array => [
                    'id' => $type->public_id,
                    'name' => $type->name,
                    'description' => $type->description,
                    'price' => $type->price->toDecimal(),
                    'price_label' => $type->price->format(),
                    'quantity_total' => $type->quantity_total,
                    'quantity_sold' => $type->quantity_sold,
                    'min_per_order' => $type->min_per_order,
                    'max_per_order' => $type->max_per_order,
                    'sales_starts_at' => LocalTime::toInput($type->sales_starts_at, $event->timezone),
                    'sales_ends_at' => LocalTime::toInput($type->sales_ends_at, $event->timezone),
                    'status' => $type->status->value,
                    'has_sales' => $type->quantity_sold > 0,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * "available", "few_left" (under 10% of capacity) or "sold_out".
     */
    public static function availability(Event $event): string
    {
        $types = self::publicTicketTypes($event);
        $capacity = $types->sum('quantity_total');
        $remaining = $types->sum(fn (TicketType $t): int => max(0, $t->quantity_total - $t->quantity_sold));

        if ($capacity === 0 || $remaining === 0) {
            return 'sold_out';
        }

        return $remaining * 10 < $capacity ? 'few_left' : 'available';
    }

    public static function salesOpen(Event $event, CarbonImmutable $now): bool
    {
        if ($event->status !== EventStatus::Published || $event->ends_at->lte($now)) {
            return false;
        }

        if ($event->sales_starts_at !== null && $now->lt($event->sales_starts_at)) {
            return false;
        }

        return $event->sales_ends_at === null || $now->lt($event->sales_ends_at);
    }

    /**
     * @return Collection<int, TicketType>
     */
    private static function publicTicketTypes(Event $event): Collection
    {
        return $event->ticketTypes
            ->filter(fn (TicketType $type): bool => $type->status !== TicketTypeStatus::Hidden)
            ->toBase()
            ->values();
    }
}
