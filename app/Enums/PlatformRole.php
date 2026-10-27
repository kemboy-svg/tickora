<?php

namespace App\Enums;

/**
 * Platform-level staff roles, held on the user record. A platform role never
 * implies organiser membership: admins act on organisers through dedicated,
 * audited admin actions rather than by bypassing organiser policies.
 */
enum PlatformRole: string
{
    case Admin = 'admin';
    case Finance = 'finance';
    case Support = 'support';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => [
                Permission::OrganizersVerify,
                Permission::EventsModerate,
                Permission::PaymentsView,
                Permission::RefundsApprove,
                Permission::AuditLogsView,
                Permission::PlatformSettingsManage,
                Permission::UsersManage,
            ],
            self::Finance => [
                Permission::PaymentsView,
                Permission::RefundsApprove,
                Permission::AuditLogsView,
            ],
            self::Support => [
                Permission::PaymentsView,
                Permission::EventsModerate,
            ],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Platform staff must use multi-factor authentication.
     */
    public function requiresMfa(): bool
    {
        return true;
    }
}
