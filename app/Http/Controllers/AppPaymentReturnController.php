<?php

namespace App\Http\Controllers;

use App\Actions\Payments\SyncOpenPayment;
use App\Models\Checkout;
use App\Models\Order;
use Illuminate\Contracts\View\View;

/**
 * The page a payment provider sends an app buyer back to, in the phone's
 * browser. The link is signed, so it needs no sign-in; it only says how the
 * payment went and hands the buyer back to the app, which shows the tickets.
 */
class AppPaymentReturnController extends Controller
{
    public function __invoke(Checkout $checkout, SyncOpenPayment $sync): View
    {
        $order = Order::query()->where('checkout_id', $checkout->id)->first();

        if ($order !== null) {
            $sync->handle($order);
        }

        $paid = $order !== null && $order->status->wasPaid();

        return view('app-payment-return', [
            'paid' => $paid,
            'appUrl' => 'tickora://checkout/'.$checkout->public_id,
        ]);
    }
}
