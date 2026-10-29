<?php

namespace App\Actions\Payments;

use App\Actions\Orders\CompleteOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Exceptions\Payments\PaymentProviderException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only place a payment is marked successful. Every provider callback,
 * including the sandbox screen's, comes through here:
 *
 *  1. The signature is verified against the raw body (forged → rejected).
 *  2. The event is logged once per provider event id, so a redelivered
 *     webhook is recognised and not applied twice.
 *  3. Success is double-checked by re-reading the payment from the
 *     provider, and the amount must match the order exactly.
 *  4. The order is completed (tickets issued) — idempotently, so a retry
 *     after a crash finishes the job without duplicating anything.
 */
class ProcessPaymentWebhook
{
    public const PROCESSED = 'processed';

    public const DUPLICATE = 'duplicate';

    public const IGNORED = 'ignored';

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly CompleteOrder $completeOrder,
    ) {}

    /**
     * @param  array<string, string|list<string>>  $headers
     *
     * @throws InvalidWebhookSignature
     * @throws PaymentProviderException
     */
    public function handle(string $provider, string $payload, array $headers): string
    {
        $gateway = $this->gateways->driver($provider);
        $event = $gateway->verifyWebhook($payload, $headers);

        $log = $this->log($event);

        if (in_array($log->processing_status, [WebhookProcessingStatus::Processed, WebhookProcessingStatus::Ignored], true)) {
            return self::DUPLICATE;
        }

        try {
            $result = $this->apply($event, $log);
        } catch (Throwable $e) {
            $log->processing_status = WebhookProcessingStatus::Failed;
            $log->error_summary = mb_substr(get_class($e).': '.$e->getMessage(), 0, 1000);
            $log->save();

            throw $e;
        }

        $log->processing_status = $result === self::IGNORED ? WebhookProcessingStatus::Ignored : WebhookProcessingStatus::Processed;
        $log->processed_at = now();
        $log->error_summary = null;
        $log->save();

        return $result;
    }

    private function log(WebhookEvent $event): PaymentWebhookEvent
    {
        return DB::transaction(function () use ($event): PaymentWebhookEvent {
            DB::table('payment_webhook_events')->insertOrIgnore([
                'provider' => $event->provider,
                'provider_event_id' => $event->providerEventId,
                'event_type' => mb_substr($event->type, 0, 80),
                'signature_valid' => true,
                'processing_status' => WebhookProcessingStatus::Received->value,
                'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $log = PaymentWebhookEvent::query()
                ->where('provider', $event->provider)
                ->where('provider_event_id', $event->providerEventId)
                ->lockForUpdate()
                ->firstOrFail();

            $log->attempts++;
            $log->save();

            return $log;
        });
    }

    private function apply(WebhookEvent $event, PaymentWebhookEvent $log): string
    {
        if (! $event->isPaymentUpdate() || $event->status === null || $event->providerPaymentId === null) {
            return self::IGNORED;
        }

        $payment = Payment::query()
            ->where('provider', $event->provider)
            ->where('provider_payment_id', $event->providerPaymentId)
            ->first();

        if ($payment === null) {
            return self::IGNORED;
        }

        $log->payment_id = $payment->id;
        $log->save();

        if ($event->amount !== null
            && ($event->amount->amount !== $payment->amount_minor || $event->amount->currency !== $payment->currency)) {
            throw new PaymentProviderException("Webhook amount does not match payment {$payment->public_id}.");
        }

        $status = $event->status;

        if ($status === PaymentStatus::Succeeded) {
            // Never trust the notification alone: ask the provider.
            $confirmed = $this->gateways->driver($payment->provider)->fetchPayment($event->providerPaymentId);

            if ($confirmed->status !== PaymentStatus::Succeeded
                || $confirmed->amount->amount !== $payment->amount_minor
                || $confirmed->amount->currency !== $payment->currency) {
                throw new PaymentProviderException("Provider does not confirm payment {$payment->public_id} as captured.");
            }
        }

        DB::transaction(function () use ($payment, $status, $event): void {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $locked->applyProviderStatus($status, $event->rawStatus);

            if ($status === PaymentStatus::Failed) {
                $failure = $event->payload['data']['failure_code'] ?? null;
                $locked->failure_code = is_string($failure) ? mb_substr($failure, 0, 80) : 'failed';
                $locked->save();

                $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

                if ($order->status === OrderStatus::PendingPayment) {
                    $order->transitionTo(OrderStatus::PaymentFailed);
                }
            }
        });

        $payment->refresh();

        // Completing is idempotent, so a redelivered or retried success
        // finishes an interrupted completion without doubling anything.
        if ($payment->status === PaymentStatus::Succeeded) {
            $this->completeOrder->handle($payment->order()->firstOrFail(), $payment);
        }

        return self::PROCESSED;
    }
}
