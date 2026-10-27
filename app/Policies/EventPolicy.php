<?php

namespace App\Policies;

use App\Enums\EventStatus;
use App\Enums\OrganizerRole;
use App\Enums\Permission;
use App\Models\Event;
use App\Models\User;

/**
 * Every check is scoped to the event's own organiser: a role in one
 * organiser grants nothing in another.
 *
 * Publication is a two-step flow: organisers submit an event for review
 * (submitForReview), platform moderators approve it (publish).
 */
class EventPolicy
{
    /**
     * See the event in the organiser workspace. Gate staff only see events
     * they are assigned to.
     */
    public function view(User $user, Event $event): bool
    {
        if ($user->hasPlatformPermission(Permission::EventsModerate)) {
            return true;
        }

        $role = $this->activeRole($user, $event);

        if ($role === null) {
            return false;
        }

        return $role !== OrganizerRole::Staff || $user->isAssignedToEvent($event);
    }

    public function update(User $user, Event $event): bool
    {
        return ! $event->status->isTerminal()
            && $user->hasOrganizerPermission($event->organizer, Permission::EventsUpdate);
    }

    public function submitForReview(User $user, Event $event): bool
    {
        return $event->status === EventStatus::Draft
            && $user->hasOrganizerPermission($event->organizer, Permission::EventsPublish);
    }

    /**
     * Approve a submitted event. Platform moderators only, and only for
     * organisers allowed to sell.
     */
    public function publish(User $user, Event $event): bool
    {
        return $event->status === EventStatus::PendingReview
            && $event->organizer->canSell()
            && $user->hasPlatformPermission(Permission::EventsModerate);
    }

    public function moderate(User $user, Event $event): bool
    {
        return $user->hasPlatformPermission(Permission::EventsModerate);
    }

    public function cancel(User $user, Event $event): bool
    {
        return $event->status->canTransitionTo(EventStatus::Cancelled)
            && $user->hasOrganizerPermission($event->organizer, Permission::EventsCancel);
    }

    /**
     * Only drafts that never sold anything can be deleted; anything else is
     * cancelled so its financial history stays intact.
     */
    public function delete(User $user, Event $event): bool
    {
        return $event->status === EventStatus::Draft
            && ! $event->orders()->exists()
            && $user->hasOrganizerPermission($event->organizer, Permission::EventsUpdate);
    }

    public function manageTicketTypes(User $user, Event $event): bool
    {
        return ! $event->status->isTerminal()
            && $user->hasOrganizerPermission($event->organizer, Permission::TicketsManage);
    }

    public function viewOrders(User $user, Event $event): bool
    {
        return $user->hasOrganizerPermission($event->organizer, Permission::OrdersView);
    }

    public function viewAttendees(User $user, Event $event): bool
    {
        return $user->hasOrganizerPermission($event->organizer, Permission::AttendeesView);
    }

    public function exportAttendees(User $user, Event $event): bool
    {
        return $user->hasOrganizerPermission($event->organizer, Permission::AttendeesExport);
    }

    public function viewReports(User $user, Event $event): bool
    {
        return $user->hasOrganizerPermission($event->organizer, Permission::ReportsView);
    }

    /**
     * Assign or revoke gate staff for this event.
     */
    public function manageStaff(User $user, Event $event): bool
    {
        return ! $event->status->isTerminal()
            && $user->hasOrganizerPermission($event->organizer, Permission::EventsUpdate);
    }

    /**
     * Scan tickets at the gate. The event must be live (published), and
     * the user must hold scan permission in its organiser. Members with the
     * Staff role additionally need a current assignment to this event;
     * owners and managers may scan any of their organiser's events.
     */
    public function scan(User $user, Event $event): bool
    {
        if ($event->status !== EventStatus::Published) {
            return false;
        }

        if (! $user->hasOrganizerPermission($event->organizer, Permission::CheckinsScan)) {
            return false;
        }

        if ($this->activeRole($user, $event) === OrganizerRole::Staff) {
            return $user->isAssignedToEvent($event);
        }

        return true;
    }

    private function activeRole(User $user, Event $event): ?OrganizerRole
    {
        if (! $user->isActive() || ! $event->organizer->status->workspaceAccessible()) {
            return null;
        }

        return $user->roleIn($event->organizer);
    }
}
