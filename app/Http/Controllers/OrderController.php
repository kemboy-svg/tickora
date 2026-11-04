<?php

namespace App\Http\Controllers;

use App\Http\Presenters\OrderPresenter;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An order and its tickets. QR codes are included only for the people
 * allowed to use the tickets (TicketPolicy): the customer or the holder.
 * Organisers and support see the order without the QR secrets.
 */
class OrderController extends Controller
{
    public function show(Request $request, Order $order): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Gate::forUser($user)->authorize('view', $order);

        $order->load(['event.venue', 'items', 'tickets' => fn ($q) => $q->orderBy('id'), 'tickets.ticketType', 'tickets.order']);
        $event = $order->event;

        return Inertia::render('orders/Show', [
            'order' => OrderPresenter::order($order, $event),
            'event' => OrderPresenter::event($event),
            'tickets' => $order->tickets
                ->map(fn (Ticket $ticket): array => OrderPresenter::ticket($user, $ticket))
                ->values()
                ->all(),
        ]);
    }
}
