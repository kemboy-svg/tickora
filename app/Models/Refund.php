<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\RefundStatus;
use App\Exceptions\InvalidStateTransition;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Requesting a refund and approving/executing it are separate, audited
 * steps performed by different roles.
 *
 * @property-read Money $amount
 */
class Refund extends Model
{
    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'requested',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
            'amount' => AsMoney::class.':amount_minor,currency',
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
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transitionTo(RefundStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw InvalidStateTransition::between('Refund', $this->status, $to);
        }

        $this->status = $to;

        match ($to) {
            RefundStatus::Approved => $this->approved_at = now(),
            RefundStatus::Succeeded, RefundStatus::Failed => $this->processed_at = now(),
            default => null,
        };

        $this->save();
    }
}
