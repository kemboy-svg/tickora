<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grants one user gate-scanning access to one event. Revoking sets
 * revoked_at; the row stays for the audit trail. Managed only through the
 * AssignEventStaff / RevokeEventStaff actions, so nothing is fillable.
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
