<?php

namespace App\Jobs;

use App\Actions\Checkout\ReleaseCheckout;
use App\Enums\CheckoutStatus;
use App\Enums\ReservationStatus;
use App\Models\Checkout;
use App\Models\InventoryReservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sweeps holds that ran out. Availability never waits for this job — the
 * checkout and the public pages ignore expired reservations by their
 * timestamp — but sweeping keeps statuses truthful and cancels the unpaid
 * orders of expired checkouts. The scheduler runs it every minute, without
 * overlapping, through the tickora:expire-holds command.
 */
class ExpireInventoryReservations implements ShouldQueue
{
    use Queueable;

    public function handle(ReleaseCheckout $release): int
    {
        $expired = 0;

        $due = Checkout::query()
            ->whereIn('status', [CheckoutStatus::Open, CheckoutStatus::AwaitingPayment])
            ->where('expires_at', '<=', now())
            ->lazyById(200);

        foreach ($due as $checkout) {
            if ($release->handle($checkout, CheckoutStatus::Expired)) {
                $expired++;
            }
        }

        // Stragglers whose checkout already ended some other way.
        InventoryReservation::query()
            ->where('status', ReservationStatus::Active)
            ->where('expires_at', '<=', now())
            ->update(['status' => ReservationStatus::Expired, 'updated_at' => now()]);

        return $expired;
    }
}
