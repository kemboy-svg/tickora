<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\Payments\PaymentProviderException;
use App\Models\Order;

/**
 * Asks the provider how the order's open payment went, rather than waiting
 * for its webhook (which may be late, or unable to reach a development
 * machine at all). Used when the buyer comes back from the payment page.
 */
class SyncOpenPayment
{
    public function __construct(private readonly UpdatePaymentStatus $updater) {}

    public function handle(Order $order): void
    {
        if ($order->status->wasPaid()) {
            return;
        }

        $payment = $order->payments()
            ->whereIn('status', [PaymentStatus::Created, PaymentStatus::RequiresAction, PaymentStatus::Processing])
            ->whereNotNull('provider_payment_id')
            ->latest('id')
            ->first();

        if ($payment === null) {
            return;
        }

        try {
            $this->updater->refresh($payment);
        } catch (PaymentProviderException $e) {
            // The webhook will settle it; the buyer sees the current state.
            report($e);
        }

        $order->refresh();
    }
}
