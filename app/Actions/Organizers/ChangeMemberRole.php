<?php

namespace App\Actions\Organizers;

use App\Enums\OrganizerRole;
use App\Models\OrganizerMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class ChangeMemberRole
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, OrganizerMember $membership, OrganizerRole $role): OrganizerMember
    {
        $organizer = $membership->organizer;

        Gate::forUser($actor)->authorize('manageTeam', $organizer);

        if ($membership->role === OrganizerRole::Owner || $role === OrganizerRole::Owner) {
            throw new InvalidArgumentException('The owner role changes only through an ownership transfer.');
        }

        if (! $membership->isActive()) {
            throw new InvalidArgumentException('Only active members can change role.');
        }

        if ($membership->role === $role) {
            return $membership;
        }

        return DB::transaction(function () use ($actor, $membership, $role, $organizer): OrganizerMember {
            $from = $membership->role;
            $membership->role = $role;
            $membership->save();

            $this->audit->record('organizer.member_role_changed', $membership, [
                'user_id' => $membership->user_id,
                'from' => $from->value,
                'to' => $role->value,
            ], actor: $actor, organizer: $organizer);

            return $membership;
        });
    }
}
