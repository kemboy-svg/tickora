<?php

namespace App\Http\Presenters;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;

/**
 * Orders, their events and their tickets, for the order and ticket pages
 * and the app. A QR code is included only when the viewer may use the
 * ticket (TicketPolicy): the customer who bought it or its holder.
 */
final class OrderPresenter
{
    /**
     * Expects items to be loaded.
     *
     * @return array<string, mixed>
     */
    public static function order(Order $order, Event $event): array
    {
        return [
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
        ];
    }

    /**
     * Expects venue to be loaded.
     *
     * @return array<string, mixed>
     */
    public static function event(Event $event): array
    {
        return [
            'id' => $event->public_id,
            'title' => $event->title,
            'slug' => $event->slug,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'date_label' => LocalTime::display($event->starts_at, $event->timezone, 'l j F Y · H:i'),
            'short_date_label' => LocalTime::display($event->starts_at, $event->timezone, 'D j M Y · H:i'),
            'timezone_label' => LocalTime::offsetLabel($event->starts_at, $event->timezone),
            'venue' => $event->venue?->name,
            'city' => $event->venue?->city,
            'address' => $event->venue === null ? null : $event->venue->address_line.', '.$event->venue->city,
            'cover_url' => $event->coverUrl(),
            'is_past' => $event->ends_at->isPast(),
        ];
    }

    /**
     * Expects ticketType and order to be loaded.
     *
     * @return array<string, mixed>
     */
    public static function ticket(User $viewer, Ticket $ticket): array
    {
        $canUse = Gate::forUser($viewer)->allows('view', $ticket);

        return [
            'id' => $ticket->public_id,
            'reference' => $ticket->reference,
            'type' => $ticket->ticketType->name,
            'attendee' => $ticket->attendee_name,
            'status' => $ticket->status->value,
            'usable' => $ticket->status === TicketStatus::Valid,
            'qr' => $canUse ? $ticket->qrPayload() : null,
            'order_id' => $ticket->order->public_id,
        ];
    }
}
