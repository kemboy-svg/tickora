<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\PayoutStatus;
use App\Models\Concerns\BelongsToOrganizer;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read Money $amount
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $organizer_id
 * @property string|null $provider
 * @property string|null $provider_transfer_id
 * @property string $currency
 * @property int $amount_minor
 * @property PayoutStatus $status
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property CarbonInterface|null $scheduled_for
 * @property CarbonInterface|null $paid_at
 * @property string|null $failure_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class OrganizerPayout extends Model
{
    use BelongsToOrganizer;
    use HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'scheduled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount_minor' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'scheduled_for' => 'date',
            'paid_at' => 'datetime',
            'amount' => AsMoney::class.':amount_minor,currency',
        ];
    }
}
