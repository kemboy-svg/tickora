<?php

namespace App\Models;

use App\Enums\WebhookProcessingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every webhook received, verified or not. The unique (provider,
 * provider_event_id) index is what guarantees each event is processed once.
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
