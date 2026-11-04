<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Enums\TicketTypeStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\EventPresenter;
use App\Models\Event;
use App\Services\Events\EventSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Event discovery for the app: the same listings and filters as the
 * website's discovery page.
 */
class EventController extends Controller
{
    public function __construct(private readonly EventSearch $search) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->search->query($this->search->filters($request))->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Event $event): array => EventPresenter::card($event))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Event $event): JsonResponse
    {
        abort_unless($event->status === EventStatus::Published || $event->status === EventStatus::Completed, 404);

        $event->load([
            'category',
            'venue',
            'organizer',
            'images',
            'ticketTypes' => fn ($query) => $query->withHeld()->where('status', '!=', TicketTypeStatus::Hidden),
        ]);

        return response()->json(['data' => EventPresenter::detail($event)]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => $this->search->categories()]);
    }
}
