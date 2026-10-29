<?php

namespace App\Http\Controllers\Organizer;

use App\Actions\Events\ChangeEventStatus;
use App\Actions\Events\ManageEventImages;
use App\Actions\Events\SaveEvent;
use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Enums\TicketTypeStatus;
use App\Http\Presenters\EventPresenter;
use App\Http\Requests\Organizer\EventRequest;
use App\Models\Category;
use App\Models\Event;
use App\Support\Currency;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organiser's events. Every event route resolves {managedEvent} through
 * Event::visibleTo(), so another organiser's event is a 404, and every action
 * is then authorised by EventPolicy.
 */
class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $organizer = $this->workspace($request);
        $status = $request->string('status')->toString();

        $events = Event::query()
            ->forOrganizer($organizer)
            ->with(['venue', 'ticketTypes'])
            ->when(
                EventStatus::tryFrom($status) !== null,
                fn ($query) => $query->where('status', $status),
            )
            ->orderByRaw("case when status = 'pending_review' then 0 when status = 'draft' then 1 when status = 'published' then 2 else 3 end")
            ->orderBy('starts_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Event $event): array => EventPresenter::organizerRow($event));

        return Inertia::render('organizer/events/Index', [
            'events' => $events,
            'filters' => ['status' => $status],
            'canCreate' => Gate::allows('createEvent', $organizer),
        ]);
    }

    public function create(Request $request): Response
    {
        $organizer = $this->workspace($request);
        Gate::authorize('createEvent', $organizer);

        return Inertia::render('organizer/events/Create', [
            ...$this->formOptions(),
            'defaults' => [
                'currency' => $organizer->default_currency,
                'country_code' => $organizer->country_code,
                'timezone' => LocalTime::defaultZoneFor($organizer->country_code),
            ],
        ]);
    }

    public function store(EventRequest $request, SaveEvent $saveEvent, ManageEventImages $images): RedirectResponse
    {
        // Process photos first: a bad file is reported before anything is saved.
        $photos = $images->prepare($request->images());

        $actor = $this->actor($request);
        $event = $saveEvent->create($actor, $this->workspace($request), $request->toData());
        $images->attach($actor, $event, $photos);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft saved. Add ticket types next.']);

        return to_route('organizer.events.edit', $event);
    }

    public function edit(Request $request, Event $managedEvent, ChangeEventStatus $status): Response
    {
        Gate::authorize('view', $managedEvent);
        $managedEvent->load(['venue', 'category', 'organizer', 'ticketTypes', 'images']);

        $user = $this->actor($request);

        return Inertia::render('organizer/events/Edit', [
            ...$this->formOptions(),
            'event' => EventPresenter::form($managedEvent),
            'readiness' => $managedEvent->status === EventStatus::Draft
                ? $status->readinessProblems($managedEvent)
                : [],
            'can' => [
                'update' => $user->can('update', $managedEvent),
                'submit' => $user->can('submitForReview', $managedEvent),
                'cancel' => $user->can('cancel', $managedEvent),
                'delete' => $user->can('delete', $managedEvent),
                'manageTicketTypes' => $user->can('manageTicketTypes', $managedEvent),
                'manageImages' => $user->can('update', $managedEvent),
            ],
            'organizerCanSell' => $managedEvent->organizer->canSell(),
            'ticketStatuses' => array_map(fn (TicketTypeStatus $s): string => $s->value, TicketTypeStatus::cases()),
        ]);
    }

    public function update(EventRequest $request, Event $managedEvent, SaveEvent $saveEvent): RedirectResponse
    {
        $saveEvent->update($this->actor($request), $managedEvent, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Event saved.']);

        return to_route('organizer.events.edit', $managedEvent);
    }

    public function destroy(Request $request, Event $managedEvent, ChangeEventStatus $status): RedirectResponse
    {
        $status->delete($this->actor($request), $managedEvent);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft deleted.']);

        return to_route('organizer.events.index');
    }

    public function submit(Request $request, Event $managedEvent, ChangeEventStatus $status): RedirectResponse
    {
        $status->submit($this->actor($request), $managedEvent);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Submitted for review. We usually review events within one working day.']);

        return to_route('organizer.events.edit', $managedEvent);
    }

    public function cancel(Request $request, Event $managedEvent, ChangeEventStatus $status): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        $status->cancel($this->actor($request), $managedEvent, $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Event cancelled.']);

        return to_route('organizer.events.edit', $managedEvent);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'categories' => Category::query()
                ->orderBy('sort_order')
                ->get(['name', 'slug'])
                ->map(fn (Category $c): array => ['name' => $c->name, 'slug' => $c->slug])
                ->all(),
            'currencies' => Currency::allowed(),
            'visibilities' => array_map(fn (EventVisibility $v): string => $v->value, EventVisibility::cases()),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'imageLimits' => [
                'max_images' => EventRequest::maxImages(),
                'max_kb' => EventRequest::MAX_IMAGE_KB,
            ],
        ];
    }
}
