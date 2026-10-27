<?php

namespace App\Actions\Organizers;

use App\Enums\MembershipStatus;
use App\Enums\OrganizerRole;
use App\Models\EventStaffAssignment;
use App\Models\OrganizerMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Revoke a member's access. Their gate-staff assignments for this
 * organiser's events are revoked in the same transaction, so a removed
 * member cannot keep scanning.
 */
class RemoveOrganizerMember
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, OrganizerMember $membership): OrganizerMember
    {
        $organizer = $membership->organizer;

        Gate::forUser($actor)->authorize('manageTeam', $organizer);

        if ($membership->role === OrganizerRole::Owner) {
            throw new InvalidArgumentException('The owner cannot be removed; transfer ownership first.');
        }

        if (! $membership->isActive()) {
            return $membership;
        }

        return DB::transaction(function () use ($actor, $membership, $organizer): OrganizerMember {
            $membership->status = MembershipStatus::Revoked;
            $membership->revoked_at = now();
            $membership->save();

            $revokedAssignments = EventStaffAssignment::query()
                ->where('user_id', $membership->user_id)
                ->whereNull('revoked_at')
                ->whereIn('event_id', $organizer->events()->select('id'))
                ->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'updated_at' => now()]);

            $this->audit->record('organizer.member_removed', $membership, [
                'user_id' => $membership->user_id,
                'role' => $membership->role->value,
                'staff_assignments_revoked' => $revokedAssignments,
            ], actor: $actor, organizer: $organizer);

            return $membership;
        });
    }
}
