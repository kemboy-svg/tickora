<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStateTransition;
use App\Models\Concerns\BelongsToOrganizer;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One event, one currency per order (MVP). Amount columns are snapshots
 * taken at checkout and never recalculated afterwards. Created only by the
 * checkout/payment actions, so nothing is mass assignable.
 *
 * @property-read Money $subtotal
 * @property-read Money $discount
 * @property-read Money $platform_fee
 * @property-read Money $tax
 * @property-read Money $total
 * @property-read Money $refunded
 */
class Order extends Model
{
    use BelongsToOrganizer;

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending_payment',
        'discount_minor' => 0,
        'platform_fee_minor' => 0,
        'tax_minor' => 0,
        'refunded_minor' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => AsMoney::class.':subtotal_minor,currency',
            'discount' => AsMoney::class.':discount_minor,currency',
            'platform_fee' => AsMoney::class.':platform_fee_minor,currency',
            'tax' => AsMoney::class.':tax_minor,currency',
            'total' => AsMoney::class.':total_minor,currency',
            'refunded' => AsMoney::class.':refunded_minor,currency',
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
     * @return BelongsTo<Checkout, $this>
     */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return HasOne<PromoCodeRedemption, $this>
     */
    public function promoRedemption(): HasOne
    {
        return $this->hasOne(PromoCodeRedemption::class);
    }

    public function belongsToCustomer(User $user): bool
    {
        return $this->user_id !== null && $this->user_id === $user->id;
    }

    public function refundableRemaining(): Money
    {
        return $this->total->minus($this->refunded);
    }

    public function transitionTo(OrderStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw InvalidStateTransition::between('Order', $this->status, $to);
        }

        $this->status = $to;

        match ($to) {
            OrderStatus::Paid => $this->paid_at ??= now(),
            OrderStatus::Cancelled => $this->cancelled_at = now(),
            default => null,
        };

        $this->save();
    }
}
