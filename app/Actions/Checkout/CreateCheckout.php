<?php

namespace App\Actions\Checkout;

use App\Enums\CheckoutStatus;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\InventoryReservation;
use App\Models\PromoCode;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Checkout\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reserves tickets for a customer and prices them.
 *
 * Overselling is prevented by locking the ticket type rows (in id order, so
 * concurrent checkouts for several types cannot deadlock) and recounting
 * availability inside the same transaction that inserts the reservations:
 *
 *   available = quantity_total − quantity_sold − tickets held by unexpired reservations
 *
 * A second buyer for the last tickets waits on the lock, then sees the first
 * buyer's committed reservation and is told how many are left.
 *
 * Prices always come from the database; the client only says which ticket
 * types and how many.
 */
class CreateCheckout
{
    public function __construct(
        private readonly Pricing $pricing,
        private readonly ResolvePromoCode $promos,
        private readonly ReleaseCheckout $release,
        private readonly Config $config,
    ) {}

    /**
     * @param  array<array-key, mixed>  $items  ticket type public id => quantity
     */
    public function handle(User $customer, Event $event, array $items, ?string $promoCode = null): Checkout
    {
        $now = CarbonImmutable::now();

        if (! $event->salesOpenAt($now) || ! $event->organizer->canSell()) {
            throw ValidationException::withMessages(['event' => 'Tickets for this event are not on sale right now.']);
        }

        $quantities = $this->normalise($items);
        $types = $this->ticketTypes($event, $quantities, $now);

        $promo = $promoCode !== null && trim($promoCode) !== ''
            ? $this->promos->handle($customer, $event, $promoCode, $now)
            : null;

        $this->releasePrevious($customer, $event);

        return DB::transaction(function () use ($customer, $event, $quantities, $types, $promo, $now): Checkout {
            // Lock in a stable order so two checkouts never wait on each other.
            $locked = TicketType::query()
                ->whereKey(array_map(fn (TicketType $t): int => $t->id, $types))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $held = InventoryReservation::query()
                ->holding()
                ->whereIn('ticket_type_id', $locked->keys())
                ->groupBy('ticket_type_id')
                ->selectRaw('ticket_type_id, sum(quantity) as held')
                ->pluck('held', 'ticket_type_id');

            $basket = [];

            foreach ($types as $type) {
                $row = $locked->get($type->id);

                if (! $row instanceof TicketType) {
                    throw ValidationException::withMessages(['items' => 'One of the selected tickets is no longer available.']);
                }

                $wanted = $quantities[$type->public_id];
                $available = max(0, $row->quantity_total - $row->quantity_sold - (int) ($held[$row->id] ?? 0));

                if ($wanted > $available) {
                    throw ValidationException::withMessages([
                        'items' => $available === 0
                            ? "{$row->name} just sold out."
                            : "Only {$available} {$row->name} ".($available === 1 ? 'ticket is' : 'tickets are').' left.',
                    ]);
                }

                $basket[] = ['type' => $row, 'quantity' => $wanted];
            }

            $quote = $this->pricing->quote($event->currency, $basket, $promo);

            if ($promo !== null && $quote->promoCode === null) {
                throw ValidationException::withMessages(['promo_code' => "The code {$promo->code} doesn't apply to the tickets you chose."]);
            }

            $expiresAt = $now->addMinutes($this->setting('tickora.reservation_ttl_minutes', 10));

            $checkout = new Checkout;
            $checkout->user_id = $customer->id;
            $checkout->event_id = $event->id;
            $checkout->promo_code_id = $quote->promoCode?->id;
            $checkout->customer_email = $customer->email;
            $checkout->currency = $event->currency;
            $checkout->subtotal_minor = $quote->subtotal->amount;
            $checkout->discount_minor = $quote->discount->amount;
            $checkout->fees_minor = $quote->fee->amount;
            $checkout->tax_minor = $quote->tax->amount;
            $checkout->total_minor = $quote->total->amount;
            $checkout->status = CheckoutStatus::Open;
            $checkout->expires_at = $expiresAt;
            $checkout->save();

            foreach ($quote->lines as $line) {
                $reservation = new InventoryReservation;
                $reservation->checkout_id = $checkout->id;
                $reservation->ticket_type_id = $line->ticketType->id;
                $reservation->quantity = $line->quantity;
                $reservation->ticket_name_snapshot = $line->ticketType->name;
                $reservation->unit_amount_minor = $line->unitPrice->amount;
                $reservation->subtotal_minor = $line->subtotal->amount;
                $reservation->discount_minor = $line->discount->amount;
                $reservation->fee_minor = $line->fee->amount;
                $reservation->tax_minor = $line->tax->amount;
                $reservation->expires_at = $expiresAt;
                $reservation->save();
            }

            return $checkout;
        });
    }

    /**
     * Re-price an open checkout with a different promo code (or none). The
     * held tickets do not change, so no inventory lock is needed; prices are
     * read fresh, which is what the customer sees before confirming.
     */
    public function reprice(Checkout $checkout, User $customer, ?string $promoCode): Checkout
    {
        $now = CarbonImmutable::now();

        $promo = $promoCode !== null && trim($promoCode) !== ''
            ? $this->promos->handle($customer, $checkout->event, $promoCode, $now)
            : null;

        return DB::transaction(function () use ($checkout, $promo): Checkout {
            $locked = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);

            if ($locked->status !== CheckoutStatus::Open || $locked->isExpired()) {
                throw ValidationException::withMessages(['checkout' => 'This checkout can no longer be changed.']);
            }

            $reservations = $locked->reservations()->with('ticketType')->orderBy('id')->get();

            $basket = array_values($reservations
                ->map(fn (InventoryReservation $r): array => ['type' => $r->ticketType, 'quantity' => $r->quantity])
                ->all());

            $quote = $this->pricing->quote($locked->currency, $basket, $promo);

            if ($promo instanceof PromoCode && $quote->promoCode === null) {
                throw ValidationException::withMessages(['promo_code' => "The code {$promo->code} doesn't apply to the tickets you chose."]);
            }

            foreach ($quote->lines as $i => $line) {
                $reservation = $reservations[$i];
                $reservation->ticket_name_snapshot = $line->ticketType->name;
                $reservation->unit_amount_minor = $line->unitPrice->amount;
                $reservation->subtotal_minor = $line->subtotal->amount;
                $reservation->discount_minor = $line->discount->amount;
                $reservation->fee_minor = $line->fee->amount;
                $reservation->tax_minor = $line->tax->amount;
                $reservation->save();
            }

            $locked->promo_code_id = $quote->promoCode?->id;
            $locked->subtotal_minor = $quote->subtotal->amount;
            $locked->discount_minor = $quote->discount->amount;
            $locked->fees_minor = $quote->fee->amount;
            $locked->tax_minor = $quote->tax->amount;
            $locked->total_minor = $quote->total->amount;
            $locked->save();

            return $locked;
        });
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array<string, int>
     */
    private function normalise(array $items): array
    {
        $quantities = [];

        foreach ($items as $id => $quantity) {
            if (! is_string($id) || ! is_numeric($quantity)) {
                continue;
            }

            $quantity = (int) $quantity;

            if ($quantity > 0) {
                $quantities[$id] = $quantity;
            }
        }

        if ($quantities === []) {
            throw ValidationException::withMessages(['items' => 'Choose at least one ticket.']);
        }

        $max = $this->setting('tickora.max_tickets_per_order', 20);

        if (array_sum($quantities) > $max) {
            throw ValidationException::withMessages(['items' => "You can buy at most {$max} tickets in one order."]);
        }

        return $quantities;
    }

    /**
     * @param  array<string, int>  $quantities
     * @return list<TicketType>
     */
    private function ticketTypes(Event $event, array $quantities, CarbonImmutable $now): array
    {
        $types = $event->ticketTypes()
            ->whereIn('public_id', array_keys($quantities))
            ->orderBy('sort_order')
            ->get();

        if ($types->count() !== count($quantities)) {
            throw ValidationException::withMessages(['items' => 'One of the selected tickets is not available for this event.']);
        }

        foreach ($types as $type) {
            $quantity = $quantities[$type->public_id];

            if (! $type->isOnSaleAt($now)) {
                throw ValidationException::withMessages(['items' => "{$type->name} tickets are not on sale right now."]);
            }

            if ($quantity < $type->min_per_order) {
                throw ValidationException::withMessages(['items' => "{$type->name} tickets are sold in groups of at least {$type->min_per_order}."]);
            }

            if ($quantity > $type->max_per_order) {
                throw ValidationException::withMessages(['items' => "You can buy at most {$type->max_per_order} {$type->name} tickets per order."]);
            }
        }

        return array_values($types->all());
    }

    /**
     * A customer has at most one live checkout per event: starting again
     * gives back what the previous one held.
     */
    private function releasePrevious(User $customer, Event $event): void
    {
        Checkout::query()
            ->where('user_id', $customer->id)
            ->where('event_id', $event->id)
            ->whereIn('status', [CheckoutStatus::Open, CheckoutStatus::AwaitingPayment])
            ->get()
            ->each(fn (Checkout $previous) => $this->release->handle($previous));
    }

    private function setting(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
