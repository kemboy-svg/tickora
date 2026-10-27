<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\LedgerEntryType;
use App\Models\Concerns\BelongsToOrganizer;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only financial record; see LedgerEntryType for the sign
 * convention. Corrections are new entries, never edits. The database
 * enforces this with a trigger; the model refuses earlier with a clearer
 * error.
 *
 * @property-read Money $amount
 * @property int $id
 * @property int $organizer_id
 * @property int|null $event_id
 * @property int|null $order_id
 * @property int|null $payment_id
 * @property int|null $refund_id
 * @property int|null $organizer_payout_id
 * @property LedgerEntryType $entry_type
 * @property string $currency
 * @property int $amount_minor
 * @property string|null $description
 * @property string|null $external_reference
 * @property string $idempotency_key
 * @property CarbonInterface|null $created_at
 */
class LedgerEntry extends Model
{
    use BelongsToOrganizer;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_type' => LedgerEntryType::class,
            'amount_minor' => 'integer',
            'amount' => AsMoney::class.':amount_minor,currency',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Ledger entries are append-only; record a correcting entry instead.');
        });

        static::deleting(function (): never {
            throw new LogicException('Ledger entries are append-only; record a correcting entry instead.');
        });
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /**
     * @return BelongsTo<OrganizerPayout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(OrganizerPayout::class, 'organizer_payout_id');
    }
}
