<?php

namespace App\Http\Controllers;

use App\Actions\Checkout\ConfirmCheckout;
use App\Actions\Checkout\CreateCheckout;
use App\Actions\Checkout\ReleaseCheckout;
use App\Actions\Orders\RefundUnfulfillableOrder;
use App\Actions\Payments\StartPayment;
use App\Actions\Payments\UpdatePaymentStatus;
use App\Enums\CheckoutStatus;
use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Payments\PaymentProviderException;
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
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

    public function show(Request $request, Checkout $checkout, UpdatePaymentStatus $updater): Response|RedirectResponse
    {
        Gate::forUser($this->customer($request))->authorize('view', $checkout);

        $checkout->load(['event.venue', 'reservations' => fn ($q) => $q->orderBy('id'), 'promoCode', 'order']);

        // Back from the provider's page: ask the provider how the payment
        // went, rather than waiting for its webhook (which may be late, or
        // unable to reach a development machine at all).
        if ($checkout->order instanceof Order && ! $checkout->order->status->wasPaid()) {
            $this->syncOpenPayment($checkout->order, $updater);
            $checkout->load('order');
        }

        // Back from the payment page with the order paid: show the tickets.
        if ($checkout->order instanceof Order && $checkout->order->status->wasPaid()) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "Payment received. You're going!"]);

            return to_route('orders.show', $checkout->order);
        }

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

    /**
     * Lock in the order, then go straight to payment (or, for a free
     * order, straight to the tickets).
     */
    public function confirm(Request $request, Checkout $checkout, ConfirmCheckout $confirm, StartPayment $pay): SymfonyResponse
    {
        $customer = $this->customer($request);
        $confirm->handle($customer, $checkout);

        return $this->toPayment($customer, $checkout, $pay);
    }

    /**
     * Pay for a confirmed order, or try again after a decline.
     */
    public function pay(Request $request, Checkout $checkout, StartPayment $pay): SymfonyResponse
    {
        return $this->toPayment($this->customer($request), $checkout, $pay);
    }

    private function syncOpenPayment(Order $order, UpdatePaymentStatus $updater): void
    {
        $payment = $order->payments()
            ->whereIn('status', [PaymentStatus::Created, PaymentStatus::RequiresAction, PaymentStatus::Processing])
            ->whereNotNull('provider_payment_id')
            ->latest('id')
            ->first();

        if ($payment === null) {
            return;
        }

        try {
            $updater->refresh($payment);
        } catch (PaymentProviderException $e) {
            // The webhook will settle it; the page shows the current state.
            report($e);
        }
    }

    private function toPayment(User $customer, Checkout $checkout, StartPayment $pay): SymfonyResponse
    {
        $result = $pay->handle($customer, $checkout);

        if ($result['redirect'] === null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "You're going! Your tickets are ready."]);

            return to_route('orders.show', $result['order']);
        }

        // The provider's page lives outside this app: a full browser visit.
        return Inertia::location($result['redirect']);
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
                'id' => $order->public_id,
                'reference' => $order->reference,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'payment_declined' => $order->status === OrderStatus::PaymentFailed,
                'refunded' => $order->refunds()->where('reason', RefundUnfulfillableOrder::REASON)->exists(),
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
