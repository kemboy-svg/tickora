<?php

namespace App\Services\Events;

use App\Enums\TicketTypeStatus;
use App\Models\Category;
use App\Models\Event;
use App\Support\Sql;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Event discovery shared by the website and the mobile app: the same
 * filters, the same sort orders, the same rule that only listed events
 * (published and public) appear.
 */
final class EventSearch
{
    public const SORTS = ['date', 'price_asc', 'price_desc', 'newest'];

    public const WHENS = ['today', 'weekend', 'week', 'month'];

    /**
     * @return array{q: string, category: string, city: string, when: string, price: string, sort: string}
     */
    public function filters(Request $request): array
    {
        $when = $request->string('when')->toString();
        $price = $request->string('price')->toString();
        $sort = $request->string('sort')->toString();

        return [
            'q' => mb_substr(trim($request->string('q')->toString()), 0, 100),
            'category' => mb_substr($request->string('category')->toString(), 0, 100),
            'city' => mb_substr(trim($request->string('city')->toString()), 0, 100),
            'when' => in_array($when, self::WHENS, true) ? $when : '',
            'price' => in_array($price, ['free', 'paid'], true) ? $price : '',
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'date',
        ];
    }

    /**
     * Listed events matching the filters, ready to paginate and present
     * as cards.
     *
     * @param  array{q: string, category: string, city: string, when: string, price: string, sort: string}  $filters
     * @return Builder<Event>
     */
    public function query(array $filters): Builder
    {
        $query = Event::query()
            ->listed()
            ->with(['category', 'venue', 'ticketTypes' => fn ($query) => $query->withHeld()])
            ->addSelect([
                'min_price_minor' => $this->minPriceSubquery(),
            ]);

        $this->applyFilters($query, $filters);

        match ($filters['sort']) {
            // Events without a price sort last.
            'price_asc' => (Sql::supportsNullsLast()
                ? $query->orderByRaw('min_price_minor asc nulls last')
                : $query->orderByRaw('min_price_minor is null')->orderBy('min_price_minor'))->orderBy('starts_at'),
            'price_desc' => (Sql::supportsNullsLast()
                ? $query->orderByRaw('min_price_minor desc nulls last')
                : $query->orderByRaw('min_price_minor is null')->orderByDesc('min_price_minor'))->orderBy('starts_at'),
            'newest' => $query->orderByDesc('published_at'),
            default => $query->orderBy('starts_at'),
        };

        return $query;
    }

    /**
     * @return list<array{name: string, slug: string}>
     */
    public function categories(): array
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

            $like = Sql::like();

            $query->where(fn (Builder $q) => $q
                ->where('title', $like, $term)
                ->orWhere('summary', $like, $term)
                ->orWhereHas('venue', fn (Builder $v) => $v->where('name', $like, $term)));
        }

        if ($filters['category'] !== '') {
            $query->whereHas('category', fn (Builder $c) => $c->where('slug', $filters['category']));
        }

        if ($filters['city'] !== '') {
            $query->whereHas('venue', fn (Builder $v) => $v->where('city', Sql::like(), $filters['city']));
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
