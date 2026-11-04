<?php

namespace App\Http\Presenters;

use App\Actions\Orders\RefundUnfulfillableOrder;
use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Models\Checkout;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Support\LocalTime;
use App\Support\Money;

/**
 * A checkout as the buyer sees it, for the checkout page and the app.
 *
 * Expects event.venue, reservations, promoCode and order to be loaded.
 */
final class CheckoutPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Checkout $checkout): array
    {
        $event = $checkout->event;

        // A hold past its time is expired even if the sweep hasn't run yet.
        $status = $checkout->status->holdsInventory() && $checkout->isExpired()
            ? CheckoutStatus::Expired
            : $checkout->status;

        $order = $checkout->order;

        return [
            'id' => $checkout->public_id,
            'status' => $status->value,
            'expires_at' => $checkout->expires_at->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($checkout->expires_at, false)),
            'event' => [
                'id' => $event->public_id,
                'title' => $event->title,
                'slug' => $event->slug,
                'date_label' => LocalTime::display($event->starts_at, $event->timezone, 'D j M Y · H:i'),
                'venue' => $event->venue?->name,
                'city' => $event->venue?->city,
                'cover_url' => $event->coverUrl(),
            ],
            'lines' => $checkout->reservations
                ->map(fn (InventoryReservation $r): array => [
                    'name' => $r->ticket_name_snapshot,
                    'quantity' => $r->quantity,
                    'unit_price' => Money::ofMinor($r->unit_amount_minor, $checkout->currency),
                    'subtotal' => Money::ofMinor($r->subtotal_minor, $checkout->currency),
                    'discount' => Money::ofMinor($r->discount_minor, $checkout->currency),
                ])
                ->values()
                ->all(),
            'subtotal' => $checkout->money('subtotal_minor'),
            'discount' => $checkout->money('discount_minor'),
            'fee' => $checkout->money('fees_minor'),
            'tax' => $checkout->money('tax_minor'),
            'total' => $checkout->money('total_minor'),
            'promo_code' => $checkout->promoCode?->code,
            'order' => $order instanceof Order ? [
                'id' => $order->public_id,
                'reference' => $order->reference,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'paid' => $order->status->wasPaid(),
                'payment_declined' => $order->status === OrderStatus::PaymentFailed,
                'refunded' => $order->refunds()->where('reason', RefundUnfulfillableOrder::REASON)->exists(),
            ] : null,
        ];
    }
}
