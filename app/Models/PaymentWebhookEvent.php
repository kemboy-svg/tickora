<?php

namespace App\Models;

use App\Enums\WebhookProcessingStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every webhook received, verified or not. The unique (provider,
 * provider_event_id) index is what guarantees each event is processed once.
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $event_type
 * @property bool $signature_valid
 * @property WebhookProcessingStatus $processing_status
 * @property int|null $payment_id
 * @property array<array-key, mixed>|null $payload
 * @property int $attempts
 * @property CarbonInterface $received_at
 * @property CarbonInterface|null $processed_at
 * @property string|null $error_summary
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PaymentWebhookEvent extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'processing_status' => 'received',
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processing_status' => WebhookProcessingStatus::class,
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
