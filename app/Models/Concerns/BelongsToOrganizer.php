<?php

namespace App\Models\Concerns;

use App\Enums\MembershipStatus;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models owned by one organiser (the tenant). Use the visibleTo() scope
 * for every organiser-side query so a member can only ever load rows of the
 * organisers they actively belong to — out-of-scope rows then 404 instead
 * of relying on a later policy check alone.
 */
trait BelongsToOrganizer
{
    /**
     * @return BelongsTo<Organizer, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    /**
     * Restrict to rows of organisers the user is an active member of.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn(
            $this->qualifyColumn('organizer_id'),
            OrganizerMember::query()
                ->select('organizer_id')
                ->where('user_id', $user->getKey())
                ->where('status', MembershipStatus::Active),
        );
    }

    /**
     * Restrict to one organiser.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForOrganizer(Builder $query, Organizer $organizer): void
    {
        $query->where($this->qualifyColumn('organizer_id'), $organizer->getKey());
    }
}
