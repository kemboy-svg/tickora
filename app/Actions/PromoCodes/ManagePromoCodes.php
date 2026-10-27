<?php

namespace App\Actions\PromoCodes;

use App\Models\Event;
use App\Models\Organizer;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * An organiser's promo codes. A code is either for one event or for all of
 * the organiser's events. Fixed discounts are in the event's currency, or
 * the organiser's default currency for organiser-wide codes.
 *
 * Codes that have been redeemed cannot be deleted (orders refer to them);
 * they are paused instead.
 */
class ManagePromoCodes
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code: string, discount_type: string, percent: string|null, amount: string|null, event: Event|null, starts_at: CarbonImmutable|null, ends_at: CarbonImmutable|null, max_redemptions: int|null, max_per_customer: int|null}  $data
     */
    public function create(User $actor, Organizer $organizer, array $data): PromoCode
    {
        Gate::forUser($actor)->authorize('managePromoCodes', $organizer);

        $code = strtoupper(trim($data['code']));
        $event = $data['event'];

        if ($event !== null && $event->organizer_id !== $organizer->id) {
            throw ValidationException::withMessages(['event' => 'Choose one of your events.']);
        }

        if (PromoCode::query()->where('organizer_id', $organizer->id)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'You already have a promo code called '.$code.'.']);
        }

        $currency = $event?->currency ?? $organizer->default_currency;

        if ($data['discount_type'] === 'percent') {
            $bps = (int) round((float) $data['percent'] * 100);

            if ($bps < 1 || $bps > 10000) {
                throw ValidationException::withMessages(['percent' => 'Enter a percentage between 0.01 and 100.']);
            }

            $value = $bps;
            $currency = null;
        } else {
            $amount = Money::of((string) $data['amount'], $currency);

            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount greater than zero.']);
            }

            $value = $amount->amount;
        }

        return DB::transaction(function () use ($actor, $organizer, $data, $code, $event, $value, $currency): PromoCode {
            $promo = new PromoCode;
            $promo->organizer_id = $organizer->id;
            $promo->event_id = $event?->id;
            $promo->code = $code;
            $promo->discount_type = $data['discount_type'];
            $promo->discount_value = $value;
            $promo->currency = $currency;
            $promo->starts_at = $data['starts_at'];
            $promo->ends_at = $data['ends_at'];
            $promo->max_redemptions = $data['max_redemptions'];
            $promo->max_per_customer = $data['max_per_customer'];
            $promo->is_active = true;
            $promo->save();

            $this->audit->record('promo_code.created', $promo, [
                'code' => $promo->code,
                'discount_type' => $promo->discount_type,
                'discount_value' => $promo->discount_value,
                'event_id' => $promo->event_id,
            ], actor: $actor, organizer: $organizer);

            return $promo;
        });
    }

    public function setActive(User $actor, Organizer $organizer, PromoCode $promo, bool $active): void
    {
        Gate::forUser($actor)->authorize('managePromoCodes', $organizer);
        abort_unless($promo->organizer_id === $organizer->id, 404);

        $promo->is_active = $active;
        $promo->save();

        $this->audit->record($active ? 'promo_code.activated' : 'promo_code.paused', $promo, [
            'code' => $promo->code,
        ], actor: $actor, organizer: $organizer);
    }

    public function delete(User $actor, Organizer $organizer, PromoCode $promo): void
    {
        Gate::forUser($actor)->authorize('managePromoCodes', $organizer);
        abort_unless($promo->organizer_id === $organizer->id, 404);

        if ($promo->redemptions_count > 0 || $promo->redemptions()->exists()) {
            throw ValidationException::withMessages(['promo_code' => 'This code has been used, so it cannot be deleted. Pause it instead.']);
        }

        DB::transaction(function () use ($actor, $organizer, $promo): void {
            $this->audit->record('promo_code.deleted', $promo, ['code' => $promo->code], actor: $actor, organizer: $organizer);
            $promo->ticketTypes()->detach();
            $promo->delete();
        });
    }
}
