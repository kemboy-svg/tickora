<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizer;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * discount_type "percent": discount_value is basis points (1000 = 10%).
 * discount_type "fixed": discount_value is minor units in `currency`.
 *
 * @property int $id
 * @property int $organizer_id
 * @property int|null $event_id
 * @property string $code
 * @property string $discount_type
 * @property int $discount_value
 * @property string|null $currency
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property int|null $max_redemptions
 * @property int $redemptions_count
 * @property int|null $max_per_customer
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'event_id', 'code', 'discount_type', 'discount_value', 'currency',
    'starts_at', 'ends_at', 'max_redemptions', 'max_per_customer', 'is_active',
])]
class PromoCode extends Model
{
    use BelongsToOrganizer;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_value' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Codes are stored uppercase so lookups are case-insensitive.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => strtoupper(trim($value)),
        );
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Empty = applies to every ticket type in scope.
     *
     * @return BelongsToMany<TicketType, $this>
     */
    public function ticketTypes(): BelongsToMany
    {
        return $this->belongsToMany(TicketType::class);
    }

    /**
     * @return HasMany<PromoCodeRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    /**
     * The discount on an eligible subtotal, never more than the subtotal.
     */
    public function discountFor(Money $eligibleSubtotal): Money
    {
        $discount = match ($this->discount_type) {
            'percent' => $eligibleSubtotal->percentageBps($this->discount_value),
            'fixed' => Money::ofMinor($this->discount_value, $this->currency ?? $eligibleSubtotal->currency),
            default => throw new InvalidArgumentException("Unknown discount type [{$this->discount_type}]."),
        };

        return $discount->greaterThan($eligibleSubtotal) ? $eligibleSubtotal : $discount;
    }
}
