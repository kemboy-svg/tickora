<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Checkout\ConfirmCheckout;
use App\Actions\Checkout\CreateCheckout;
use App\Actions\Checkout\ReleaseCheckout;
use App\Actions\Payments\StartPayment;
use App\Actions\Payments\SyncOpenPayment;
use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CheckoutPresenter;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * Buying tickets from the app: the same hold, pricing, confirmation and
 * payment steps as the website. Payment happens on the provider's page in
 * the phone's browser, which then returns the buyer to the app.
 */
class CheckoutController extends Controller
{
    public function store(Request $request, CreateCheckout $create): JsonResponse
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

        return response()->json(['data' => $this->present($checkout)], 201);
    }

    /**
     * Also how the app learns that a payment went through: it asks the
     * provider about the open payment, as the website's return page does.
     */
    public function show(Request $request, Checkout $checkout, SyncOpenPayment $sync): JsonResponse
    {
        Gate::forUser($this->customer($request))->authorize('view', $checkout);

        $order = Order::query()->where('checkout_id', $checkout->id)->first();

        if ($order !== null) {
            $sync->handle($order);
        }

        return response()->json(['data' => $this->present($checkout)]);
    }

    public function promo(Request $request, Checkout $checkout, CreateCheckout $create): JsonResponse
    {
        $customer = $this->customer($request);
        Gate::forUser($customer)->authorize('update', $checkout);

        $validated = $request->validate(['promo_code' => ['nullable', 'string', 'max:40']]);
        $code = $validated['promo_code'] ?? null;

        $create->reprice($checkout, $customer, is_string($code) ? $code : null);

        return response()->json(['data' => $this->present($checkout)]);
    }

    /**
     * Confirm the order (once) and open payment. A free order completes
     * here; otherwise the app opens payment_url in the browser.
     */
    public function pay(Request $request, Checkout $checkout, ConfirmCheckout $confirm, StartPayment $pay): JsonResponse
    {
        $customer = $this->customer($request);

        if (! Order::query()->where('checkout_id', $checkout->id)->exists()) {
            $confirm->handle($customer, $checkout);
        }

        $result = $pay->handle($customer, $checkout, URL::signedRoute('app.payment-return', $checkout));

        return response()->json(['data' => [
            'order_id' => $result['order']->public_id,
            'paid' => $result['redirect'] === null,
            'payment_url' => $this->paymentUrl($result['redirect'], $result['payment']),
        ]]);
    }

    public function destroy(Request $request, Checkout $checkout, ReleaseCheckout $release): JsonResponse
    {
        Gate::forUser($this->customer($request))->authorize('update', $checkout);

        $release->handle($checkout);

        return response()->json(['message' => 'Your tickets were released.']);
    }

    /**
     * The phone's browser is not signed in to the website, so the sandbox
     * payment screen is opened through a signed link instead.
     */
    private function paymentUrl(?string $redirect, ?Payment $payment): ?string
    {
        if ($redirect === null || $payment === null || $payment->provider !== 'fake'
            || $payment->provider_payment_id === null || ! Route::has('sandbox.payments.show')) {
            return $redirect;
        }

        return URL::temporarySignedRoute('sandbox.payments.show', now()->addHour(), [
            'paymentId' => $payment->provider_payment_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Checkout $checkout): array
    {
        $checkout->refresh()->load(['event.venue', 'reservations' => fn ($q) => $q->orderBy('id'), 'promoCode', 'order']);

        return CheckoutPresenter::present($checkout);
    }

    private function customer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
