<?php

namespace App\Http\Controllers\Public;

use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Enums\TicketTypeStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\EventPresenter;
use App\Models\Category;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public discovery and event pages. Only published events appear in
 * listings; unlisted events are reachable by direct link; drafts, events
 * under review and cancelled events are never public.
 */
class EventController extends Controller
{
    private const SORTS = ['date', 'price_asc', 'price_desc', 'newest'];

    private const WHENS = ['today', 'weekend', 'week', 'month'];

    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim($request->string('q')->toString()),
            'category' => $request->string('category')->toString(),
            'city' => trim($request->string('city')->toString()),
            'when' => in_array($request->string('when')->toString(), self::WHENS, true) ? $request->string('when')->toString() : '',
            'price' => in_array($request->string('price')->toString(), ['free', 'paid'], true) ? $request->string('price')->toString() : '',
            'sort' => in_array($request->string('sort')->toString(), self::SORTS, true) ? $request->string('sort')->toString() : 'date',
        ];

        $query = Event::query()
            ->listed()
            ->with(['category', 'venue', 'ticketTypes'])
            ->addSelect([
                'min_price_minor' => $this->minPriceSubquery(),
            ]);

        $this->applyFilters($query, $filters);

        match ($filters['sort']) {
            'price_asc' => $query->orderByRaw('min_price_minor asc nulls last')->orderBy('starts_at'),
            'price_desc' => $query->orderByRaw('min_price_minor desc nulls last')->orderBy('starts_at'),
            'newest' => $query->orderByDesc('published_at'),
            default => $query->orderBy('starts_at'),
        };

        $events = $query
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Event $event): array => EventPresenter::card($event));

        return Inertia::render('public/events/Index', [
            'events' => $events,
            'filters' => $filters,
            'categories' => $this->categories(),
        ]);
    }

    public function show(Event $event): Response
    {
        abort_unless($event->status === EventStatus::Published || $event->status === EventStatus::Completed, 404);

        $event->load([
            'category',
            'venue',
            'organizer',
            'ticketTypes' => fn ($query) => $query->where('status', '!=', TicketTypeStatus::Hidden),
        ]);

        $related = Event::query()
            ->listed()
            ->whereKeyNot($event->id)
            ->where('category_id', $event->category_id)
            ->with(['category', 'venue', 'ticketTypes'])
            ->orderBy('starts_at')
            ->limit(3)
            ->get()
            ->map(fn (Event $related): array => EventPresenter::card($related))
            ->all();

        return Inertia::render('public/events/Show', [
            'event' => EventPresenter::detail($event),
            'related' => $related,
            'unlisted' => $event->visibility === EventVisibility::Unlisted,
        ]);
    }

    /**
     * @return list<array{name: string, slug: string}>
     */
    private function categories(): array
    {
        return array_values(Category::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Category $category): array => ['name' => $category->name, 'slug' => $category->slug])
            ->all());
    }

    /**
     * @param  Builder<Event>  $query
     * @param  array{q: string, category: string, city: string, when: string, price: string, sort: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['q'] !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $filters['q']).'%';

            $query->where(fn (Builder $q) => $q
                ->where('title', 'ilike', $term)
                ->orWhere('summary', 'ilike', $term)
                ->orWhereHas('venue', fn (Builder $v) => $v->where('name', 'ilike', $term)));
        }

        if ($filters['category'] !== '') {
            $query->whereHas('category', fn (Builder $c) => $c->where('slug', $filters['category']));
        }

        if ($filters['city'] !== '') {
            $query->whereHas('venue', fn (Builder $v) => $v->where('city', 'ilike', $filters['city']));
        }

        if ($filters['when'] !== '') {
            [$from, $to] = $this->window($filters['when']);
            $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
        }

        if ($filters['price'] === 'free') {
            $query->whereHas('ticketTypes', fn (Builder $t) => $t
                ->where('status', TicketTypeStatus::Active)
                ->where('unit_amount_minor', 0));
        } elseif ($filters['price'] === 'paid') {
            $query->whereHas('ticketTypes', fn (Builder $t) => $t
                ->where('status', TicketTypeStatus::Active)
                ->where('unit_amount_minor', '>', 0));
        }
    }

    /**
     * Date windows in UTC. Good enough for discovery; the event page shows
     * exact local times.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function window(string $when): array
    {
        $now = CarbonImmutable::now();

        return match ($when) {
            'today' => [$now, $now->endOfDay()],
            'weekend' => [
                $now->isWeekend() ? $now : $now->next(CarbonImmutable::SATURDAY)->startOfDay(),
                $now->isSunday() ? $now->endOfDay() : $now->next(CarbonImmutable::SUNDAY)->endOfDay(),
            ],
            'week' => [$now, $now->addDays(7)],
            default => [$now, $now->addDays(30)],
        };
    }

    private function minPriceSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('ticket_types')
            ->selectRaw('min(unit_amount_minor)')
            ->whereColumn('ticket_types.event_id', 'events.id')
            ->where('ticket_types.status', TicketTypeStatus::Active->value);
    }
}
