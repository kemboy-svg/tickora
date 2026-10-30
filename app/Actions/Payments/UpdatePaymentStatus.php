<?php

namespace App\Actions\Payments;

use App\Actions\Orders\CompleteOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Payments\PaymentProviderException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;

/**
 * Moves a payment to the provider's status and, on success, completes the
 * order. Success is only ever taken from the provider's own record
 * (refresh()), never from a browser redirect or an unverified message.
 *
 * Used by the webhook handler and when a customer returns from the
 * provider's page, so payments complete even if a webhook is late or
 * cannot reach this server (local development).
 */
class UpdatePaymentStatus
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly CompleteOrder $completeOrder,
    ) {}

    /**
     * Re-read the payment from its provider and apply what it says.
     *
     * @throws PaymentProviderException when the provider's record does not match the payment
     */
    public function refresh(Payment $payment): Payment
    {
        if ($payment->provider_payment_id === null) {
            return $payment;
        }

        $remote = $this->gateways->driver($payment->provider)->fetchPayment($payment->provider_payment_id);

        if ($remote->status === PaymentStatus::Succeeded
            && ($remote->amount->amount !== $payment->amount_minor || $remote->amount->currency !== $payment->currency)) {
            throw new PaymentProviderException("Provider amount does not match payment {$payment->public_id}.");
        }

        return $this->apply($payment, $remote->status, $remote->rawStatus, $remote->failureCode);
    }

    /**
     * Apply a status that is not a success claim (failed, processing…),
     * or one already confirmed with the provider.
     */
    public function apply(Payment $payment, PaymentStatus $status, ?string $rawStatus, ?string $failureCode = null): Payment
    {
        DB::transaction(function () use ($payment, $status, $rawStatus, $failureCode): void {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $changed = $locked->applyProviderStatus($status, $rawStatus !== null ? mb_substr($rawStatus, 0, 60) : null);

            if ($changed && $status === PaymentStatus::Failed) {
                $locked->failure_code = mb_substr($failureCode ?? 'failed', 0, 80);
                $locked->save();

                $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

                if ($order->status === OrderStatus::PendingPayment) {
                    $order->transitionTo(OrderStatus::PaymentFailed);
                }
            }
        });

        $payment->refresh();

        // Idempotent: a repeat finishes an interrupted completion and
        // otherwise does nothing.
        if ($payment->status === PaymentStatus::Succeeded) {
            $this->completeOrder->handle($payment->order()->firstOrFail(), $payment);
        }

        return $payment;
    }
}
