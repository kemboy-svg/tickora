<?php

namespace App\Enums;

/**
 * Fine-grained abilities. Roles map onto these; policies check them together
 * with tenant scope (which organiser, which event), never on their own.
 */
enum Permission: string
{
    // Organiser-scoped
    case OrganizerUpdate = 'organizer.update';
    case TeamManage = 'team.manage';
    case EventsCreate = 'events.create';
    case EventsUpdate = 'events.update';
    case EventsPublish = 'events.publish';
    case EventsCancel = 'events.cancel';
    case TicketsManage = 'tickets.manage';
    case OrdersView = 'orders.view';
    case AttendeesView = 'attendees.view';
    case AttendeesExport = 'attendees.export';
    case CheckinsScan = 'checkins.scan';
    case ReportsView = 'reports.view';
    case RefundsRequest = 'refunds.request';
    case PayoutsView = 'payouts.view';
    case PayoutsManage = 'payouts.manage';

    // Platform-scoped
    case OrganizersVerify = 'organizers.verify';
    case EventsModerate = 'events.moderate';
    case PaymentsView = 'payments.view';
    case RefundsApprove = 'refunds.approve';
    case AuditLogsView = 'audit_logs.view';
    case PlatformSettingsManage = 'platform.settings.manage';
    case UsersManage = 'users.manage';
}
