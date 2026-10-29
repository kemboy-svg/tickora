<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\Payments\PaymentProviderException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;

/**
 * A payment arrived after the customer's hold lapsed and the tickets were
 * sold to someone else. No tickets were issued, so the full amount goes
 * straight back: this refund is automatic and needs no approval. The order
 * stays (or becomes) cancelled.
 *
 * The provider call happens outside any database transaction. If it fails,
 * the refund is left as "failed" with the reason, for finance to retry.
 */
class RefundUnfulfillableOrder
{
    public const REASON = 'Tickets sold out before the payment arrived.';

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Order $order, Payment $payment): ?Refund
    {
        $refund = DB::transaction(function () use ($order, $payment): ?Refund {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status !== PaymentStatus::Succeeded) {
                return null; // Already refunded, or never captured.
            }

            $existing = Refund::query()->where('idempotency_key', "unfulfillable:{$lockedPayment->id}")->first();

            if ($existing !== null) {
                return $existing->status === RefundStatus::Succeeded ? null : $existing;
            }

            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->status->canTransitionTo(OrderStatus::Cancelled)) {
                $lockedOrder->transitionTo(OrderStatus::Cancelled);
            }

            $refund = new Refund;
            $refund->order_id = $lockedOrder->id;
            $refund->payment_id = $lockedPayment->id;
            $refund->idempotency_key = "unfulfillable:{$lockedPayment->id}";
            $refund->currency = $lockedPayment->currency;
            $refund->amount_minor = $lockedPayment->amount_minor;
            $refund->reason = self::REASON;
            // System refund for undelivered tickets: no approval step.
            $refund->status = RefundStatus::Processing;
            $refund->approved_at = now();
            $refund->save();

            $this->audit->record('order.refunded_unfulfillable', $lockedOrder, [
                'reference' => $lockedOrder->reference,
                'amount_minor' => $refund->amount_minor,
            ], organizer: $lockedOrder->organizer);

            return $refund;
        });

        if ($refund === null || $refund->status !== RefundStatus::Processing) {
            return $refund;
        }

        try {
            $result = $this->gateways->driver($payment->provider)->refund(new RefundRequest(
                providerPaymentId: (string) $payment->provider_payment_id,
                amount: $refund->amount,
                idempotencyKey: $refund->idempotency_key,
                reason: self::REASON,
            ));
        } catch (PaymentProviderException $e) {
            $refund->failure_reason = $e->getMessage();
            $refund->transitionTo(RefundStatus::Failed);
            report($e);

            return $refund;
        }

        DB::transaction(function () use ($refund, $payment, $result): void {
            $refund->provider_refund_id = $result->providerRefundId;
            $refund->transitionTo(RefundStatus::Succeeded);

            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $lockedPayment->refunded_minor = $lockedPayment->amount_minor;
            $lockedPayment->applyProviderStatus(PaymentStatus::Refunded, 'refunded');
            $lockedPayment->save();
        });

        return $refund;
    }
}
