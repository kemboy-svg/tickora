<?php

namespace App\Http\Controllers\Public;

use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Enums\TicketTypeStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\EventPresenter;
use App\Models\Event;
use App\Services\Events\EventSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public discovery and event pages. Only published events appear in
 * listings; unlisted events are reachable by direct link; drafts, events
 * under review and cancelled events are never public.
 */
class EventController extends Controller
{
    public function __construct(private readonly EventSearch $search) {}

    public function index(Request $request): Response
    {
        $filters = $this->search->filters($request);

        $events = $this->search->query($filters)
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Event $event): array => EventPresenter::card($event));

        return Inertia::render('public/events/Index', [
            'events' => $events,
            'filters' => $filters,
            'categories' => $this->search->categories(),
        ]);
    }

    public function show(Event $event): Response
    {
        abort_unless($event->status === EventStatus::Published || $event->status === EventStatus::Completed, 404);

        $event->load([
            'category',
            'venue',
            'organizer',
            'images',
            'ticketTypes' => fn ($query) => $query->withHeld()->where('status', '!=', TicketTypeStatus::Hidden),
        ]);

        $related = Event::query()
            ->listed()
            ->whereKeyNot($event->id)
            ->where('category_id', $event->category_id)
            ->with(['category', 'venue', 'ticketTypes' => fn ($query) => $query->withHeld()])
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
}
