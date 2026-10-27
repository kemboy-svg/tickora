<?php

namespace App\Http\Controllers;

use App\Actions\Checkout\ConfirmCheckout;
use App\Actions\Checkout\CreateCheckout;
use App\Actions\Checkout\ReleaseCheckout;
use App\Enums\CheckoutStatus;
use App\Enums\EventStatus;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's side of buying tickets: hold, review, confirm, release.
 * Every checkout is visible only to the customer who started it.
 */
class CheckoutController extends Controller
{
    public function store(Request $request, CreateCheckout $create): RedirectResponse
    {
        $validated = $request->validate([
            'event' => ['required', 'string', 'max:26'],
            'items' => ['required', 'array', 'max:50'],
            'items.*' => ['integer', 'min:0', 'max:1000'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);

        $event = Event::query()
            ->where('public_id', $validated['event'])
            ->where('status', EventStatus::Published)
            ->with('organizer')
            ->firstOrFail();

        /** @var array<array-key, mixed> $items */
        $items = $validated['items'];
        $promo = $validated['promo_code'] ?? null;

        $checkout = $create->handle($this->customer($request), $event, $items, is_string($promo) ? $promo : null);

        return to_route('checkout.show', $checkout);
    }

    public function show(Request $request, Checkout $checkout): Response
    {
        Gate::forUser($this->customer($request))->authorize('view', $checkout);

        $checkout->load(['event.venue', 'reservations' => fn ($q) => $q->orderBy('id'), 'promoCode', 'order']);

        return Inertia::render('checkout/Show', [
            'checkout' => $this->present($checkout),
        ]);
    }

    public function promo(Request $request, Checkout $checkout, CreateCheckout $create): RedirectResponse
    {
        $customer = $this->customer($request);
        Gate::forUser($customer)->authorize('update', $checkout);

        $validated = $request->validate(['promo_code' => ['nullable', 'string', 'max:40']]);
        $code = $validated['promo_code'] ?? null;

        $create->reprice($checkout, $customer, is_string($code) ? $code : null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => is_string($code) && $code !== '' ? 'Promo code applied.' : 'Promo code removed.',
        ]);

        return to_route('checkout.show', $checkout);
    }

    public function confirm(Request $request, Checkout $checkout, ConfirmCheckout $confirm): RedirectResponse
    {
        $confirm->handle($this->customer($request), $checkout);

        return to_route('checkout.show', $checkout);
    }

    public function destroy(Request $request, Checkout $checkout, ReleaseCheckout $release): RedirectResponse
    {
        Gate::forUser($this->customer($request))->authorize('update', $checkout);

        $release->handle($checkout);
        $event = $checkout->event()->firstOrFail();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your tickets were released.']);

        return to_route('events.show', ['event' => $event->slug]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Checkout $checkout): array
    {
        $event = $checkout->event;

        // A hold past its time is expired even if the sweep hasn't run yet.
        $status = $checkout->status->holdsInventory() && $checkout->isExpired()
            ? CheckoutStatus::Expired
            : $checkout->status;

        $order = $checkout->order;

        return [
            'id' => $checkout->public_id,
            'status' => $status->value,
            'expires_at' => $checkout->expires_at->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($checkout->expires_at, false)),
            'event' => [
                'title' => $event->title,
                'slug' => $event->slug,
                'date_label' => LocalTime::display($event->starts_at, $event->timezone, 'D j M Y · H:i'),
                'venue' => $event->venue?->name,
                'city' => $event->venue?->city,
            ],
            'lines' => $checkout->reservations
                ->map(fn (InventoryReservation $r): array => [
                    'name' => $r->ticket_name_snapshot,
                    'quantity' => $r->quantity,
                    'unit_price' => Money::ofMinor($r->unit_amount_minor, $checkout->currency),
                    'subtotal' => Money::ofMinor($r->subtotal_minor, $checkout->currency),
                    'discount' => Money::ofMinor($r->discount_minor, $checkout->currency),
                ])
                ->values()
                ->all(),
            'subtotal' => $checkout->money('subtotal_minor'),
            'discount' => $checkout->money('discount_minor'),
            'fee' => $checkout->money('fees_minor'),
            'tax' => $checkout->money('tax_minor'),
            'total' => $checkout->money('total_minor'),
            'promo_code' => $checkout->promoCode?->code,
            'order' => $order instanceof Order ? [
                'reference' => $order->reference,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
            ] : null,
        ];
    }

    private function customer(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
