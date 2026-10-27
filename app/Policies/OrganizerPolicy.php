<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Organizer;
use App\Models\User;

class OrganizerPolicy
{
    /**
     * Open the organiser workspace: any active member, or platform staff
     * reviewing the organiser.
     */
    public function view(User $user, Organizer $organizer): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->hasPlatformPermission(Permission::OrganizersVerify)) {
            return true;
        }

        return $organizer->status->workspaceAccessible() && $user->membershipFor($organizer) !== null;
    }

    public function update(User $user, Organizer $organizer): bool
    {
        return $user->hasOrganizerPermission($organizer, Permission::OrganizerUpdate);
    }

    /**
     * Invite, remove and change roles of members.
     */
    public function manageTeam(User $user, Organizer $organizer): bool
    {
        return $user->hasOrganizerPermission($organizer, Permission::TeamManage);
    }

    public function createEvent(User $user, Organizer $organizer): bool
    {
        return $user->hasOrganizerPermission($organizer, Permission::EventsCreate);
    }

    public function viewReports(User $user, Organizer $organizer): bool
    {
        return $user->hasOrganizerPermission($organizer, Permission::ReportsView);
    }

    public function viewPayouts(User $user, Organizer $organizer): bool
    {
        return $user->hasOrganizerPermission($organizer, Permission::PayoutsView);
    }

    /**
     * Change the payout account or currency. Owner only; strongly
     * recommended to require MFA at the controller level as well.
     */
    public function managePayouts(User $user, Organizer $organizer): bool
    {
        return $user->hasOrganizerPermission($organizer, Permission::PayoutsManage);
    }

    /**
     * Verify, suspend or reject an organiser. Platform staff only.
     */
    public function changeStatus(User $user, Organizer $organizer): bool
    {
        return $user->hasPlatformPermission(Permission::OrganizersVerify);
    }
}
