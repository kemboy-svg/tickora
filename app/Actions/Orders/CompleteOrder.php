<?php

namespace App\Actions\Orders;

use App\Enums\CheckoutStatus;
use App\Enums\LedgerEntryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Mail\OrderConfirmed;
use App\Models\Checkout;
use App\Models\InventoryReservation;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Audit\AuditLogger;
use App\Support\Reference;
use App\Support\TicketToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Throwable;

/**
 * Turns a paid (or free) order into tickets. Idempotent: completing an
 * order that is already paid does nothing, so duplicate webhooks are safe.
 *
 * In one transaction, with the ticket type rows locked:
 *  - the checkout's holds become sales (quantity_sold goes up),
 *  - the order becomes paid and the checkout completed,
 *  - one ticket per seat is issued with its own QR secret,
 *  - a promo code use is recorded,
 *  - the organiser's ledger is credited with the ticket revenue.
 *
 * If the hold had lapsed and the tickets were sold to someone else in the
 * meantime, nothing is issued and the payment is refunded in full
 * (RefundUnfulfillableOrder). The confirmation email goes out after commit.
 */
class CompleteOrder
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RefundUnfulfillableOrder $refundUnfulfillable,
    ) {}

    public function handle(Order $order, ?Payment $payment = null): Order
    {
        $fulfilled = DB::transaction(function () use ($order, $payment): ?bool {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status->wasPaid()) {
                return null; // Already done.
            }

            $this->assertPaidFor($locked, $payment);

            /** @var list<OrderItem> $items */
            $items = array_values($locked->items()->orderBy('id')->get()->all());

            $types = TicketType::query()
                ->whereKey(array_map(fn (OrderItem $i): int => $i->ticket_type_id, $items))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $checkout = $locked->checkout_id !== null
                ? Checkout::query()->lockForUpdate()->find($locked->checkout_id)
                : null;

            if (! $this->canFulfil($items, $types->all(), $checkout)) {
                return false;
            }

            foreach ($items as $item) {
                $type = $types->get($item->ticket_type_id);

                if (! $type instanceof TicketType) {
                    throw new LogicException("Ticket type {$item->ticket_type_id} vanished.");
                }

                $type->quantity_sold += $item->quantity;
                $type->save();
            }

            if ($checkout !== null) {
                $checkout->reservations()
                    ->where('status', ReservationStatus::Active)
                    ->update(['status' => ReservationStatus::Converted, 'updated_at' => now()]);

                $checkout->status = CheckoutStatus::Completed;
                $checkout->completed_at = now();
                $checkout->save();
            }

            $locked->transitionTo(OrderStatus::Paid);

            $this->issueTickets($locked, $items);
            $this->redeemPromo($locked, $checkout);
            $this->credit($locked, $payment);

            $this->audit->record('order.paid', $locked, [
                'reference' => $locked->reference,
                'total_minor' => $locked->total_minor,
                'currency' => $locked->currency,
                'payment' => $payment?->public_id,
            ], organizer: $locked->organizer);

            return true;
        });

        $order->refresh();

        if ($fulfilled === false && $payment !== null) {
            $this->refundUnfulfillable->handle($order, $payment);
            $order->refresh();
        }

        if ($fulfilled === true) {
            $this->sendConfirmation($order);
        }

        return $order;
    }

    private function assertPaidFor(Order $order, ?Payment $payment): void
    {
        if ($order->total_minor === 0) {
            return;
        }

        if ($payment === null
            || $payment->order_id !== $order->id
            || $payment->status !== PaymentStatus::Succeeded
            || $payment->amount_minor !== $order->total_minor
            || $payment->currency !== $order->currency) {
            throw new LogicException("Order {$order->reference} has no matching successful payment.");
        }
    }

    /**
     * Every item is covered either by this checkout's live hold or by
     * tickets nobody else holds right now.
     *
     * @param  list<OrderItem>  $items
     * @param  array<int, TicketType>  $types
     */
    private function canFulfil(array $items, array $types, ?Checkout $checkout): bool
    {
        foreach ($items as $item) {
            $type = $types[$item->ticket_type_id] ?? null;

            if ($type === null) {
                return false;
            }

            $held = $checkout !== null && InventoryReservation::query()
                ->holding()
                ->where('checkout_id', $checkout->id)
                ->where('ticket_type_id', $type->id)
                ->where('quantity', '>=', $item->quantity)
                ->exists();

            if ($held) {
                continue;
            }

            $heldByOthers = (int) InventoryReservation::query()
                ->holding()
                ->where('ticket_type_id', $type->id)
                ->when($checkout !== null, fn ($q) => $q->where('checkout_id', '!=', $checkout?->id))
                ->sum('quantity');

            if ($item->quantity > $type->quantity_total - $type->quantity_sold - $heldByOthers) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<OrderItem>  $items
     */
    private function issueTickets(Order $order, array $items): void
    {
        $sequence = 0;

        foreach ($items as $item) {
            for ($i = 0; $i < $item->quantity; $i++) {
                $sequence++;

                $ticket = new Ticket;
                $ticket->reference = Reference::ticket($order->reference, $sequence);
                $ticket->order_id = $order->id;
                $ticket->order_item_id = $item->id;
                $ticket->event_id = $order->event_id;
                $ticket->ticket_type_id = $item->ticket_type_id;
                $ticket->holder_user_id = $order->user_id;
                $ticket->attendee_name = $order->customer_name;
                $ticket->attendee_email = $order->customer_email;
                $ticket->useToken(TicketToken::issue());
                $ticket->status = TicketStatus::Valid;
                $ticket->issued_at = now();
                $ticket->save();
            }
        }
    }

    private function redeemPromo(Order $order, ?Checkout $checkout): void
    {
        if ($checkout?->promo_code_id === null || $order->discount_minor === 0) {
            return;
        }

        $promo = PromoCode::query()->lockForUpdate()->find($checkout->promo_code_id);

        if ($promo === null) {
            return;
        }

        // The customer was shown this discount and has paid, so it is
        // honoured; the counter stops at the limit rather than breaking it.
        if ($promo->max_redemptions === null || $promo->redemptions_count < $promo->max_redemptions) {
            $promo->redemptions_count++;
            $promo->save();
        }

        $redemption = new PromoCodeRedemption;
        $redemption->promo_code_id = $promo->id;
        $redemption->order_id = $order->id;
        $redemption->user_id = $order->user_id;
        $redemption->currency = $order->currency;
        $redemption->discount_minor = $order->discount_minor;
        $redemption->save();
    }

    /**
     * The organiser earns the ticket revenue after discounts. The booking
     * fee is paid by the customer on top and is the platform's, so it is
     * not an organiser ledger entry.
     */
    private function credit(Order $order, ?Payment $payment): void
    {
        $revenue = $order->subtotal_minor - $order->discount_minor;

        if ($revenue <= 0) {
            return;
        }

        $entry = new LedgerEntry;
        $entry->organizer_id = $order->organizer_id;
        $entry->event_id = $order->event_id;
        $entry->order_id = $order->id;
        $entry->payment_id = $payment?->id;
        $entry->entry_type = LedgerEntryType::TicketSale;
        $entry->currency = $order->currency;
        $entry->amount_minor = $revenue;
        $entry->description = "Ticket sales for order {$order->reference}";
        $entry->idempotency_key = "order:{$order->id}:ticket_sale";
        $entry->save();
    }

    private function sendConfirmation(Order $order): void
    {
        try {
            Mail::to($order->customer_email)->send(new OrderConfirmed($order));
        } catch (Throwable $e) {
            // The order is paid and the tickets exist; a mail outage must
            // not undo that. Customers can always open their tickets online.
            report($e);
        }
    }
}
