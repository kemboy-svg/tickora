<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\CheckoutStatus;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A short-lived cart that holds inventory while the customer pays. Amounts
 * are always calculated server-side; client-supplied prices are never used.
 * Written only by the checkout actions, so nothing is mass assignable.
 *
 * @property-read Money $subtotal
 * @property-read Money $total
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property int $event_id
 * @property int|null $promo_code_id
 * @property string $customer_email
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $fees_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property CheckoutStatus $status
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Checkout extends Model
{
    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'subtotal_minor' => 0,
        'discount_minor' => 0,
        'fees_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CheckoutStatus::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'subtotal' => AsMoney::class.':subtotal_minor,currency',
            'total' => AsMoney::class.':total_minor,currency',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * @return HasMany<InventoryReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    /**
     * @return HasOne<Order, $this>
     */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
