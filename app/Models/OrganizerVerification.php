<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organizer_id
 * @property string $provider
 * @property string|null $provider_reference
 * @property VerificationStatus $status
 * @property array<array-key, mixed>|null $document_keys
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property string|null $review_notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['provider', 'provider_reference', 'document_keys'])]
#[Hidden(['document_keys'])]
class OrganizerVerification extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'submitted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'document_keys' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organizer, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
