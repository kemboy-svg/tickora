<?php

namespace App\Actions\Organizers;

use App\Enums\MembershipStatus;
use App\Enums\OrganizerRole;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Add an existing user to an organiser's team (or reinstate a revoked
 * member). Email invitations for people without an account come later and
 * will end in this action.
 */
class AddOrganizerMember
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Organizer $organizer, User $user, OrganizerRole $role): OrganizerMember
    {
        Gate::forUser($actor)->authorize('manageTeam', $organizer);

        if ($role === OrganizerRole::Owner) {
            throw new InvalidArgumentException('Ownership can only be transferred by the current owner, not granted.');
        }

        return DB::transaction(function () use ($actor, $organizer, $user, $role): OrganizerMember {
            $membership = OrganizerMember::query()
                ->where('organizer_id', $organizer->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($membership?->status === MembershipStatus::Active) {
                throw new InvalidArgumentException('That user is already a member of this organiser.');
            }

            $membership ??= new OrganizerMember;
            $membership->organizer_id = $organizer->id;
            $membership->user_id = $user->id;
            $membership->role = $role;
            $membership->status = MembershipStatus::Active;
            $membership->invited_by = $actor->id;
            $membership->joined_at = now();
            $membership->revoked_at = null;
            $membership->save();

            $this->audit->record('organizer.member_added', $membership, [
                'user_id' => $user->id,
                'role' => $role->value,
            ], actor: $actor, organizer: $organizer);

            return $membership;
        });
    }
}
