<?php

namespace App\Actions\Checkout;

use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Models\Checkout;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Turns an open checkout into a pending order, ready for payment.
 *
 * The order and its items are snapshots of the reservations: names and
 * amounts are copied, never recalculated, so later edits to the event or
 * ticket types cannot change what this customer is charged. The hold is
 * extended to the payment window so the bank's verification step has time
 * to finish.
 */
class ConfirmCheckout
{
    public function __construct(private readonly Config $config) {}

    public function handle(User $customer, Checkout $checkout): Order
    {
        Gate::forUser($customer)->authorize('update', $checkout);

        return DB::transaction(function () use ($customer, $checkout): Order {
            $locked = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);

            if ($locked->status === CheckoutStatus::AwaitingPayment && ! $locked->isExpired()) {
                // Confirming twice (double click, back button) returns the same order.
                return Order::query()->where('checkout_id', $locked->id)->firstOrFail();
            }

            if ($locked->status !== CheckoutStatus::Open || $locked->isExpired()) {
                throw ValidationException::withMessages(['checkout' => 'This checkout has expired. Your tickets were released; please choose them again.']);
            }

            /** @var list<InventoryReservation> $reservations */
            $reservations = array_values($locked->reservations()->orderBy('id')->get()->all());

            foreach ($reservations as $reservation) {
                if ($reservation->status !== ReservationStatus::Active || $reservation->expires_at->isPast()) {
                    throw ValidationException::withMessages(['checkout' => 'Your ticket hold has ended. Please choose your tickets again.']);
                }
            }

            $event = $locked->event()->firstOrFail();

            $order = new Order;
            $order->reference = $this->uniqueReference();
            $order->checkout_id = $locked->id;
            $order->user_id = $customer->id;
            $order->customer_email = $locked->customer_email;
            $order->customer_name = $customer->name;
            $order->organizer_id = $event->organizer_id;
            $order->event_id = $event->id;
            $order->currency = $locked->currency;
            $order->subtotal_minor = $locked->subtotal_minor;
            $order->discount_minor = $locked->discount_minor;
            $order->platform_fee_minor = $locked->fees_minor;
            $order->tax_minor = $locked->tax_minor;
            $order->total_minor = $locked->total_minor;
            $order->status = OrderStatus::PendingPayment;
            $order->save();

            foreach ($reservations as $reservation) {
                $item = new OrderItem;
                $item->order_id = $order->id;
                $item->ticket_type_id = $reservation->ticket_type_id;
                $item->ticket_name_snapshot = $reservation->ticket_name_snapshot ?? 'Ticket';
                $item->quantity = $reservation->quantity;
                $item->unit_amount_minor = $reservation->unit_amount_minor;
                $item->discount_minor = $reservation->discount_minor;
                $item->line_total_minor = $reservation->subtotal_minor - $reservation->discount_minor;
                $item->fee_minor = $reservation->fee_minor;
                $item->tax_minor = $reservation->tax_minor;
                $item->save();
            }

            $paymentDeadline = CarbonImmutable::now()->addMinutes($this->paymentWindow());

            // Only ever extend a hold, never shorten one.
            if ($paymentDeadline->gt($locked->expires_at)) {
                $locked->expires_at = $paymentDeadline;
                $locked->reservations()
                    ->where('status', ReservationStatus::Active)
                    ->update(['expires_at' => $paymentDeadline, 'updated_at' => now()]);
            }

            $locked->status = CheckoutStatus::AwaitingPayment;
            $locked->save();

            $checkout->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    private function uniqueReference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = Reference::order();

            if (! Order::query()->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException('Could not allocate a unique order reference.');
    }

    private function paymentWindow(): int
    {
        $value = $this->config->get('tickora.payment_window_minutes', 15);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 15;
    }
}
