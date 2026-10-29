<?php

namespace App\Actions\Payments;

use App\Actions\Orders\CompleteOrder;
use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Data\CheckoutRequest;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sends the customer to the payment provider for a confirmed checkout, or
 * completes a free order on the spot.
 *
 * One payment attempt is open at a time: pressing "Pay" again (or going
 * back and forth) reuses the open attempt with the same idempotency key,
 * so the provider never creates a second charge. After a decline, the
 * next press starts a fresh attempt while the hold lasts.
 */
class StartPayment
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly CompleteOrder $completeOrder,
    ) {}

    /**
     * @return array{order: Order, redirect: string|null} redirect is null when no payment is needed
     */
    public function handle(User $customer, Checkout $checkout): array
    {
        Gate::forUser($customer)->authorize('update', $checkout);

        $order = Order::query()->where('checkout_id', $checkout->id)->first();

        if ($order === null) {
            throw ValidationException::withMessages(['checkout' => 'Confirm your tickets before paying.']);
        }

        if ($order->status->wasPaid()) {
            return ['order' => $order, 'redirect' => null];
        }

        $checkout->refresh();

        if ($checkout->status !== CheckoutStatus::AwaitingPayment || $checkout->isExpired()
            || ! in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::PaymentFailed], true)) {
            throw ValidationException::withMessages(['checkout' => 'This checkout has expired. Your tickets were released; please choose them again.']);
        }

        if ($order->total_minor === 0) {
            return ['order' => $this->completeOrder->handle($order), 'redirect' => null];
        }

        $gateway = $this->gateways->driver();

        if (! $gateway->supportsCurrency($order->currency)) {
            throw ValidationException::withMessages(['checkout' => "Online payment in {$order->currency} isn't available yet."]);
        }

        $payment = DB::transaction(function () use ($order, $gateway): Payment {
            Order::query()->lockForUpdate()->findOrFail($order->id);

            $open = Payment::query()
                ->where('order_id', $order->id)
                ->where('provider', $gateway->name())
                ->whereIn('status', [PaymentStatus::Created, PaymentStatus::RequiresAction, PaymentStatus::Processing])
                ->latest('id')
                ->first();

            if ($open !== null) {
                return $open;
            }

            $attempt = Payment::query()->where('order_id', $order->id)->count() + 1;

            $payment = new Payment;
            $payment->order_id = $order->id;
            $payment->provider = $gateway->name();
            $payment->idempotency_key = "order:{$order->public_id}:attempt:{$attempt}";
            $payment->currency = $order->currency;
            $payment->amount_minor = $order->total_minor;
            $payment->status = PaymentStatus::Created;
            $payment->save();

            return $payment;
        });

        $returnUrl = route('checkout.show', $checkout);

        $session = $gateway->createCheckout(new CheckoutRequest(
            idempotencyKey: $payment->idempotency_key,
            amount: $order->total,
            orderReference: $order->reference,
            customerEmail: $order->customer_email,
            returnUrl: $returnUrl,
            cancelUrl: $returnUrl,
            metadata: ['order' => $order->public_id],
        ));

        if ($payment->provider_payment_id === null) {
            $payment->provider_payment_id = $session->providerPaymentId;
            $payment->provider_status = $session->rawStatus;
            $payment->save();
        }

        return ['order' => $order, 'redirect' => $session->redirectUrl];
    }
}
