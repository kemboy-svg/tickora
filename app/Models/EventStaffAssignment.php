<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grants one user gate-scanning access to one event. Revoking sets
 * revoked_at; the row stays for the audit trail. Managed only through the
 * AssignEventStaff / RevokeEventStaff actions, so nothing is fillable.
 *
 * @property int $id
 * @property int $event_id
 * @property int $user_id
 * @property int|null $assigned_by
 * @property CarbonInterface|null $revoked_at
 * @property int|null $revoked_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class EventStaffAssignment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isCurrent(): bool
    {
        return $this->revoked_at === null;
    }
}
