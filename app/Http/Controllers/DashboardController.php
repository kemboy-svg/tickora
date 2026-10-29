<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in customer's home: tickets on hold, upcoming events they
 * have orders for, and past orders. Only the customer's own records.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                OrderStatus::Paid,
                OrderStatus::PartiallyRefunded,
                OrderStatus::PendingPayment,
                OrderStatus::PaymentFailed,
            ])
            ->with(['event.venue', 'checkout'])
            ->withSum('items', 'quantity')
            ->latest('id')
            ->limit(100)
            ->get();

        $now = now();

        $upcoming = $orders
            ->filter(fn (Order $o): bool => $o->event->ends_at->gt($now))
            ->sortBy(fn (Order $o): int => $o->event->starts_at->getTimestamp())
            ->values();

        $past = $orders
            ->filter(fn (Order $o): bool => $o->event->ends_at->lte($now) && $o->status->wasPaid())
            ->take(10)
            ->values();

        $holds = Checkout::query()
            ->where('user_id', $user->id)
            ->holding()
            ->whereDoesntHave('order')
            ->with('event')
            ->withSum('reservations', 'quantity')
            ->latest('id')
            ->get();

        $paidUpcoming = $upcoming->filter(fn (Order $o): bool => $o->status->wasPaid());

        return Inertia::render('Dashboard', [
            'stats' => [
                'upcoming_events' => $paidUpcoming->pluck('event_id')->unique()->count(),
                'upcoming_tickets' => (int) $paidUpcoming->sum('items_sum_quantity'),
                'orders' => $orders->filter(fn (Order $o): bool => $o->status->wasPaid())->count(),
            ],
            'upcoming' => $upcoming->map(fn (Order $o): array => $this->order($o))->all(),
            'past' => $past->map(fn (Order $o): array => $this->order($o))->all(),
            'holds' => $holds->map(fn (Checkout $c): array => [
                'id' => $c->public_id,
                'event' => $c->event->title,
                'tickets' => (int) ($c->getAttribute('reservations_sum_quantity') ?? 0),
                'total' => $c->money('total_minor'),
                'expires_at' => $c->expires_at->toIso8601String(),
                'minutes_left' => max(1, (int) ceil(now()->diffInSeconds($c->expires_at, false) / 60)),
            ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function order(Order $order): array
    {
        $event = $order->event;
        $checkout = $order->checkout;

        return [
            'id' => $order->public_id,
            'reference' => $order->reference,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'tickets' => (int) ($order->getAttribute('items_sum_quantity') ?? 0),
            'total' => $order->total,
            'paid_label' => $order->paid_at?->diffForHumans(),
            // Unpaid orders link back to their checkout while it still holds tickets.
            'checkout_id' => ! $order->status->wasPaid() && $checkout instanceof Checkout && $checkout->isHolding()
                ? $checkout->public_id
                : null,
            'event' => $this->event($event),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function event(Event $event): array
    {
        return [
            'title' => $event->title,
            'slug' => $event->slug,
            'date_label' => LocalTime::display($event->starts_at, $event->timezone, 'D j M Y · H:i'),
            'venue' => $event->venue?->name,
            'city' => $event->venue?->city,
            'cover_url' => $event->coverUrl(),
        ];
    }
}
