<?php

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ticket type on an order, with price and name snapshots. Amounts are
 * in the order's currency, which lineTotal() takes from the caller.
 *
 * @property int $id
 * @property int $order_id
 * @property int $ticket_type_id
 * @property string $ticket_name_snapshot
 * @property int $quantity
 * @property int $unit_amount_minor
 * @property int $discount_minor
 * @property int $line_total_minor
 * @property int $fee_minor
 * @property int $tax_minor
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class OrderItem extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'discount_minor' => 0,
        'fee_minor' => 0,
        'tax_minor' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'discount_minor' => 'integer',
            'line_total_minor' => 'integer',
            'fee_minor' => 'integer',
            'tax_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<TicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function lineTotal(string $currency): Money
    {
        return Money::ofMinor($this->line_total_minor, $currency);
    }
}
