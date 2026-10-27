<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Exceptions\InvalidStateTransition;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tickets held for a checkout until it is paid or expires. Active
 * reservations count against a ticket type's availability.
 *
 * @property int $id
 * @property int $checkout_id
 * @property int $ticket_type_id
 * @property int $quantity
 * @property string|null $ticket_name_snapshot
 * @property int $unit_amount_minor
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $fee_minor
 * @property int $tax_minor
 * @property ReservationStatus $status
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class InventoryReservation extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'fee_minor' => 'integer',
            'tax_minor' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Checkout, $this>
     */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    /**
     * @return BelongsTo<TicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /**
     * Reservations still holding inventory right now.
     *
     * @param  Builder<static>  $query
     */
    public function scopeHolding(Builder $query): void
    {
        $query->where('status', ReservationStatus::Active)->where('expires_at', '>', now());
    }

    public function transitionTo(ReservationStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw InvalidStateTransition::between('Reservation', $this->status, $to);
        }

        $this->status = $to;
        $this->save();
    }
}
