<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
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
            'order' => [
                'id' => $order->public_id,
                'reference' => $order->reference,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'paid_label' => LocalTime::display($order->paid_at, $event->timezone, 'D j M Y, H:i'),
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'items' => $order->items->map(fn ($item): array => [
                    'name' => $item->ticket_name_snapshot,
                    'quantity' => $item->quantity,
                    'amount' => Money::ofMinor($item->unit_amount_minor * $item->quantity, $order->currency),
                ])->values()->all(),
                'subtotal' => $order->subtotal,
                'discount' => $order->discount,
                'fee' => Money::ofMinor($order->platform_fee_minor + $order->tax_minor, $order->currency),
                'total' => $order->total,
            ],
            'event' => [
                'title' => $event->title,
                'slug' => $event->slug,
                'date_label' => LocalTime::display($event->starts_at, $event->timezone, 'l j F Y · H:i'),
                'timezone_label' => LocalTime::offsetLabel($event->starts_at, $event->timezone),
                'venue' => $event->venue?->name,
                'address' => $event->venue === null ? null : $event->venue->address_line.', '.$event->venue->city,
                'cover_url' => $event->coverUrl(),
            ],
            'tickets' => $order->tickets
                ->map(fn (Ticket $ticket): array => $this->ticket($user, $ticket))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(User $user, Ticket $ticket): array
    {
        $canUse = Gate::forUser($user)->allows('view', $ticket);

        return [
            'id' => $ticket->public_id,
            'reference' => $ticket->reference,
            'type' => $ticket->ticketType->name,
            'attendee' => $ticket->attendee_name,
            'status' => $ticket->status->value,
            'usable' => $ticket->status === TicketStatus::Valid,
            'qr' => $canUse ? $ticket->qrPayload() : null,
        ];
    }
}
