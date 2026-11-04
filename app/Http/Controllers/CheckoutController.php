<?php

namespace App\Http\Controllers;

use App\Actions\Checkout\ConfirmCheckout;
use App\Actions\Checkout\CreateCheckout;
use App\Actions\Checkout\ReleaseCheckout;
use App\Actions\Payments\StartPayment;
use App\Actions\Payments\SyncOpenPayment;
use App\Enums\EventStatus;
use App\Http\Presenters\CheckoutPresenter;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
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

    public function show(Request $request, Checkout $checkout, SyncOpenPayment $sync): Response|RedirectResponse
    {
        Gate::forUser($this->customer($request))->authorize('view', $checkout);

        $checkout->load(['event.venue', 'reservations' => fn ($q) => $q->orderBy('id'), 'promoCode', 'order']);

        // Back from the provider's page: ask the provider how the payment
        // went, rather than waiting for its webhook (which may be late, or
        // unable to reach a development machine at all).
        if ($checkout->order instanceof Order && ! $checkout->order->status->wasPaid()) {
            $sync->handle($checkout->order);
            $checkout->load('order');
        }

        // Back from the payment page with the order paid: show the tickets.
        if ($checkout->order instanceof Order && $checkout->order->status->wasPaid()) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "Payment received. You're going!"]);

            return to_route('orders.show', $checkout->order);
        }

        return Inertia::render('checkout/Show', [
            'checkout' => CheckoutPresenter::present($checkout),
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

    private function customer(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
