<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Events\ChangeEventStatus;
use App\Enums\EventStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Presenters\EventPresenter;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform moderators review submitted events: approve to publish, or send
 * back to the organiser with notes.
 */
class EventReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $this->moderator($request);

        $events = Event::query()
            ->where('status', EventStatus::PendingReview)
            ->with(['organizer', 'venue', 'category', 'ticketTypes', 'images'])
            ->orderBy('submitted_at')
            ->paginate(20)
            ->through(fn (Event $event): array => [
                ...EventPresenter::detail($event),
                'submitted_label' => $event->submitted_at?->diffForHumans(),
                'organizer_status' => $event->organizer->status->value,
                'readiness' => app(ChangeEventStatus::class)->readinessProblems($event),
            ]);

        return Inertia::render('admin/events/Index', ['events' => $events]);
    }

    public function approve(Request $request, Event $event, ChangeEventStatus $status): RedirectResponse
    {
        $status->approve($this->moderator($request), $event);

        Inertia::flash('toast', ['type' => 'success', 'message' => "\"{$event->title}\" is now live."]);

        return to_route('admin.events.index');
    }

    public function reject(Request $request, Event $event, ChangeEventStatus $status): RedirectResponse
    {
        $validated = $request->validate(['notes' => ['required', 'string', 'min:10', 'max:2000']]);

        $status->reject($this->moderator($request), $event, $validated['notes']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent back to the organiser with your notes.']);

        return to_route('admin.events.index');
    }

    private function moderator(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasPlatformPermission(Permission::EventsModerate), 403);

        return $user;
    }
}
