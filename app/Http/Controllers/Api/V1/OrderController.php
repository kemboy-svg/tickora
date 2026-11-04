<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\OrderPresenter;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The buyer's orders with their tickets and QR codes, so the app can keep
 * them on the phone and show them at the door without a connection.
 */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->customer($request);

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded])
            ->with(['event.venue', 'items', 'tickets' => fn ($q) => $q->orderBy('id'), 'tickets.ticketType', 'tickets.order'])
            ->latest('id')
            ->limit(100)
            ->get()
            ->sortBy(fn (Order $order): int => $order->event->starts_at->getTimestamp())
            ->values();

        return response()->json([
            'data' => $orders->map(fn (Order $order): array => $this->present($user, $order))->all(),
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $this->customer($request);
        Gate::forUser($user)->authorize('view', $order);

        $order->load(['event.venue', 'items', 'tickets' => fn ($q) => $q->orderBy('id'), 'tickets.ticketType', 'tickets.order']);

        return response()->json(['data' => $this->present($user, $order)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, Order $order): array
    {
        return [
            ...OrderPresenter::order($order, $order->event),
            'event' => OrderPresenter::event($order->event),
            'tickets' => $order->tickets
                ->map(fn (Ticket $ticket): array => OrderPresenter::ticket($user, $ticket))
                ->values()
                ->all(),
        ];
    }

    private function customer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
