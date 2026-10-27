<?php

namespace App\Enums;

/**
 * A user's role inside one organiser. Staff only ever act on events they are
 * individually assigned to (see EventStaffAssignment); the role alone does
 * not grant access to every event of the organiser.
 */
enum OrganizerRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => [
                Permission::OrganizerUpdate,
                Permission::TeamManage,
                Permission::EventsCreate,
                Permission::EventsUpdate,
                Permission::EventsPublish,
                Permission::EventsCancel,
                Permission::TicketsManage,
                Permission::OrdersView,
                Permission::AttendeesView,
                Permission::AttendeesExport,
                Permission::CheckinsScan,
                Permission::ReportsView,
                Permission::RefundsRequest,
                Permission::PayoutsView,
                Permission::PayoutsManage,
            ],
            // Managers run events and sales but cannot change the team,
            // the payout account, or the organiser's ownership.
            self::Manager => [
                Permission::EventsCreate,
                Permission::EventsUpdate,
                Permission::EventsPublish,
                Permission::EventsCancel,
                Permission::TicketsManage,
                Permission::OrdersView,
                Permission::AttendeesView,
                Permission::AttendeesExport,
                Permission::CheckinsScan,
                Permission::ReportsView,
                Permission::RefundsRequest,
                Permission::PayoutsView,
            ],
            // Gate staff scan tickets for assigned events and nothing else:
            // no financial data, no bulk attendee access.
            self::Staff => [
                Permission::CheckinsScan,
            ],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::Staff => 'Event staff',
        };
    }
}
