<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidStateTransition;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to collect an order's total through a provider. A failed
 * attempt stays failed; retrying creates a new Payment.
 *
 * @property-read Money $amount
 * @property-read Money $refunded
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property string $provider
 * @property string|null $provider_account_id
 * @property string|null $provider_payment_id
 * @property string|null $provider_status
 * @property string $idempotency_key
 * @property string $currency
 * @property int $amount_minor
 * @property int $refunded_minor
 * @property PaymentStatus $status
 * @property CarbonInterface|null $authorized_at
 * @property CarbonInterface|null $captured_at
 * @property CarbonInterface|null $failed_at
 * @property string|null $failure_code
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Payment extends Model
{
    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'created',
        'refunded_minor' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'refunded_minor' => 'integer',
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'failed_at' => 'datetime',
            'amount' => AsMoney::class.':amount_minor,currency',
            'refunded' => AsMoney::class.':refunded_minor,currency',
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
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return HasMany<PaymentWebhookEvent, $this>
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class);
    }

    /**
     * Apply a status the provider reported. Returns false (and changes
     * nothing) for a transition the state machine does not allow — e.g. a
     * stale "processing" webhook arriving after "succeeded" — so webhook
     * handlers can safely ignore out-of-order events.
     */
    public function applyProviderStatus(PaymentStatus $to, ?string $rawStatus = null): bool
    {
        if ($to === $this->status) {
            return false;
        }

        if (! $this->status->canTransitionTo($to)) {
            return false;
        }

        $this->status = $to;
        $this->provider_status = $rawStatus ?? $to->value;

        match ($to) {
            PaymentStatus::Succeeded => $this->captured_at ??= now(),
            PaymentStatus::Failed => $this->failed_at ??= now(),
            default => null,
        };

        $this->save();

        return true;
    }

    /**
     * Strict variant for internal callers that must not hit an invalid move.
     */
    public function transitionTo(PaymentStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw InvalidStateTransition::between('Payment', $this->status, $to);
        }

        $this->applyProviderStatus($to);
    }
}
