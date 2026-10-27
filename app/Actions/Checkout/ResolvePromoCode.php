<?php

namespace App\Actions\Checkout;

use App\Models\Event;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Finds a promo code a customer may use on an event, or explains why not.
 *
 * Redemption limits are checked here against redemptions already recorded.
 * Redemptions are recorded when an order is paid, and the payment step
 * re-checks the limits under a lock, so this is the customer-facing check,
 * not the final word.
 */
class ResolvePromoCode
{
    public function handle(User $customer, Event $event, string $code, CarbonInterface $now): PromoCode
    {
        $code = strtoupper(trim($code));

        $promo = PromoCode::query()
            ->where('organizer_id', $event->organizer_id)
            ->where('code', $code)
            ->where(fn ($q) => $q->whereNull('event_id')->orWhere('event_id', $event->id))
            ->with('ticketTypes')
            ->first();

        if ($promo === null || ! $promo->is_active) {
            $this->fail("The code {$code} isn't valid for this event.");
        }

        if ($promo->starts_at !== null && $now->lt($promo->starts_at)) {
            $this->fail('This code is not active yet.');
        }

        if ($promo->ends_at !== null && $now->gte($promo->ends_at)) {
            $this->fail('This code has expired.');
        }

        if ($promo->discount_type === 'fixed' && $promo->currency !== $event->currency) {
            $this->fail("The code {$code} isn't valid for this event.");
        }

        if ($promo->max_redemptions !== null && $promo->redemptions_count >= $promo->max_redemptions) {
            $this->fail('This code has been fully redeemed.');
        }

        if ($promo->max_per_customer !== null) {
            $used = PromoCodeRedemption::query()
                ->where('promo_code_id', $promo->id)
                ->where('user_id', $customer->id)
                ->count();

            if ($used >= $promo->max_per_customer) {
                $this->fail("You've already used this code the maximum number of times.");
            }
        }

        return $promo;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['promo_code' => $message]);
    }
}
