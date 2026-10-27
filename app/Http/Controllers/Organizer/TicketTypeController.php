<?php

namespace App\Http\Controllers\Organizer;

use App\Actions\TicketTypes\SaveTicketType;
use App\Http\Requests\Organizer\TicketTypeRequest;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Ticket types are nested under the event and bound with scoped bindings,
 * so a ticket type of another event (or organiser) is a 404.
 */
class TicketTypeController extends Controller
{
    public function store(TicketTypeRequest $request, Event $managedEvent, SaveTicketType $save): RedirectResponse
    {
        $save->handle($this->actor($request), $managedEvent, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ticket type added.']);

        return to_route('organizer.events.edit', $managedEvent);
    }

    public function update(TicketTypeRequest $request, Event $managedEvent, TicketType $ticketType, SaveTicketType $save): RedirectResponse
    {
        $save->handle($this->actor($request), $managedEvent, $request->toData(), $ticketType);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ticket type saved.']);

        return to_route('organizer.events.edit', $managedEvent);
    }

    public function destroy(Request $request, Event $managedEvent, TicketType $ticketType, SaveTicketType $save): RedirectResponse
    {
        $save->delete($this->actor($request), $managedEvent, $ticketType);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ticket type deleted.']);

        return to_route('organizer.events.edit', $managedEvent);
    }
}
