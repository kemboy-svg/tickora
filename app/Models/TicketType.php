<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\TicketTypeStatus;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A priced, capacity-limited kind of ticket for one event (Free, Early Bird,
 * Regular, VIP, VVIP …). Its currency always matches the event's.
 *
 * @property-read Money $price
 */
#[Fillable([
    'name', 'description', 'currency', 'unit_amount_minor', 'quantity_total',
    'sales_starts_at', 'sales_ends_at', 'min_per_order', 'max_per_order', 'sort_order', 'status',
])]
class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'quantity_sold' => 0,
        'min_per_order' => 1,
        'max_per_order' => 10,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketTypeStatus::class,
            'unit_amount_minor' => 'integer',
            'quantity_total' => 'integer',
            'quantity_sold' => 'integer',
            'min_per_order' => 'integer',
            'max_per_order' => 'integer',
            'sales_starts_at' => 'datetime',
            'sales_ends_at' => 'datetime',
            'price' => AsMoney::class.':unit_amount_minor,currency',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<InventoryReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function isFree(): bool
    {
        return $this->unit_amount_minor === 0;
    }

    /**
     * Within this ticket type's own sales window and not paused or hidden.
     * The event's publication and sales window are checked separately.
     */
    public function isOnSaleAt(CarbonInterface $moment): bool
    {
        if ($this->status !== TicketTypeStatus::Active) {
            return false;
        }

        if ($this->sales_starts_at !== null && $moment->lt($this->sales_starts_at)) {
            return false;
        }

        return $this->sales_ends_at === null || $moment->lt($this->sales_ends_at);
    }
}
