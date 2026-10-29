<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\ProcessPaymentWebhook;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Fake\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The sandbox provider's "hosted payment page", for development only (the
 * routes do not exist in production). Pressing Pay or Decline makes the
 * sandbox send its signed webhook, which goes through exactly the same
 * processing as a real provider's, then returns the customer to Tickora.
 */
class SandboxPaymentController extends Controller
{
    public function show(Request $request, string $paymentId, PaymentGatewayManager $gateways): Response
    {
        [$payment, $session] = $this->resolve($request, $paymentId, $gateways);
        $payment->loadMissing('order.event');

        return Inertia::render('sandbox/Pay', [
            'payment' => [
                'id' => $paymentId,
                'amount' => $session['amount'],
                'reference' => $session['reference'],
                'status' => $session['status']->value,
                'event' => $payment->order->event->title,
                'email' => $payment->order->customer_email,
                'cancel_url' => $session['cancel_url'],
            ],
        ]);
    }

    public function update(Request $request, string $paymentId, PaymentGatewayManager $gateways, ProcessPaymentWebhook $process): HttpResponse
    {
        [, $session, $gateway] = $this->resolve($request, $paymentId, $gateways);

        $outcome = $request->validate(['outcome' => ['required', 'in:succeed,decline']])['outcome'];

        if ($session['status'] === PaymentStatus::Created) {
            $webhook = $gateway->simulate(
                $paymentId,
                $outcome === 'succeed' ? PaymentStatus::Succeeded : PaymentStatus::Failed,
                $outcome === 'decline' ? 'card_declined' : null,
            );

            $process->handle($gateway->name(), $webhook['payload'], $webhook['headers']);
        }

        return Inertia::location($session['return_url']);
    }

    /**
     * @return array{Payment, array{amount: Money, reference: string, status: PaymentStatus, return_url: string, cancel_url: string}, FakePaymentGateway}
     */
    private function resolve(Request $request, string $paymentId, PaymentGatewayManager $gateways): array
    {
        $gateway = $gateways->driver('fake');
        abort_unless($gateway instanceof FakePaymentGateway, 404);

        $payment = Payment::query()
            ->where('provider', 'fake')
            ->where('provider_payment_id', $paymentId)
            ->with('order')
            ->first();

        $user = $request->user();

        // Only the customer paying may open their payment screen.
        abort_unless($payment !== null && $user instanceof User && $payment->order->belongsToCustomer($user), 404);

        $session = $gateway->session($paymentId);
        abort_if($session === null, 404);

        return [$payment, $session, $gateway];
    }
}
