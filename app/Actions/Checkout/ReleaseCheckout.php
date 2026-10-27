<?php

namespace App\Actions\Checkout;

use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Models\Checkout;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Ends a checkout that still holds tickets, returning them to sale.
 *
 * Used when the customer abandons it (Cancelled), when they start a new
 * checkout for the same event (Cancelled), and by the expiry sweep
 * (Expired). An unpaid order created from the checkout is cancelled with it.
 * Completed checkouts are left untouched, so calling this is always safe.
 */
class ReleaseCheckout
{
    public function handle(Checkout $checkout, CheckoutStatus $to = CheckoutStatus::Cancelled): bool
    {
        return DB::transaction(function () use ($checkout, $to): bool {
            $locked = Checkout::query()->lockForUpdate()->find($checkout->id);

            if ($locked === null || ! $locked->status->holdsInventory()) {
                return false;
            }

            $locked->reservations()
                ->where('status', ReservationStatus::Active)
                ->update([
                    'status' => $to === CheckoutStatus::Expired ? ReservationStatus::Expired : ReservationStatus::Released,
                    'updated_at' => now(),
                ]);

            $locked->status = $to;
            $locked->save();

            $order = Order::query()->where('checkout_id', $locked->id)->lockForUpdate()->first();

            if ($order !== null && $order->status->canTransitionTo(OrderStatus::Cancelled)) {
                $order->transitionTo(OrderStatus::Cancelled);
            }

            $checkout->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
