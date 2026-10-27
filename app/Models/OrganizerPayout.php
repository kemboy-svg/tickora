<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Enums\PayoutStatus;
use App\Models\Concerns\BelongsToOrganizer;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read Money $amount
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
