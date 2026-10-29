<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One ticket, full screen, for showing at the door. Only the holder or
 * the customer who bought it may open it: its QR code gets them in.
 */
class TicketController extends Controller
{
    public function show(Request $request, Ticket $ticket): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $ticket->load(['order', 'event.venue', 'ticketType']);
        Gate::forUser($user)->authorize('view', $ticket);

        $event = $ticket->event;

        return Inertia::render('tickets/Show', [
            'ticket' => [
                'id' => $ticket->public_id,
                'reference' => $ticket->reference,
                'type' => $ticket->ticketType->name,
                'attendee' => $ticket->attendee_name,
                'status' => $ticket->status->value,
                'usable' => $ticket->status === TicketStatus::Valid,
                'qr' => $ticket->qrPayload(),
                'order_id' => $ticket->order->public_id,
            ],
            'event' => [
                'title' => $event->title,
                'slug' => $event->slug,
                'date_label' => LocalTime::display($event->starts_at, $event->timezone, 'D j M Y · H:i'),
                'venue' => $event->venue?->name,
                'city' => $event->venue?->city,
                'cover_url' => $event->coverUrl(),
            ],
        ]);
    }
}
