<?php

namespace Tests\Unit;

use App\Enums\OrganizerRole;
use App\Enums\Permission;
use App\Enums\PlatformRole;
use PHPUnit\Framework\TestCase;

class RolesTest extends TestCase
{
    public function test_gate_staff_can_only_scan(): void
    {
        $this->assertSame([Permission::CheckinsScan], OrganizerRole::Staff->permissions());
    }

    public function test_managers_cannot_change_the_team_or_payout_account(): void
    {
        $this->assertFalse(OrganizerRole::Manager->can(Permission::TeamManage));
        $this->assertFalse(OrganizerRole::Manager->can(Permission::PayoutsManage));
        $this->assertFalse(OrganizerRole::Manager->can(Permission::OrganizerUpdate));
        $this->assertTrue(OrganizerRole::Manager->can(Permission::EventsCreate));
        $this->assertTrue(OrganizerRole::Manager->can(Permission::PayoutsView));
    }

    public function test_owners_hold_every_organiser_permission(): void
    {
        foreach (OrganizerRole::Manager->permissions() as $permission) {
            $this->assertTrue(OrganizerRole::Owner->can($permission), $permission->value);
        }

        $this->assertTrue(OrganizerRole::Owner->can(Permission::TeamManage));
        $this->assertTrue(OrganizerRole::Owner->can(Permission::PayoutsManage));
    }

    public function test_organiser_roles_never_include_platform_permissions(): void
    {
        $platformOnly = [
            Permission::OrganizersVerify, Permission::EventsModerate, Permission::PaymentsView,
            Permission::RefundsApprove, Permission::AuditLogsView, Permission::PlatformSettingsManage,
            Permission::UsersManage,
        ];

        foreach (OrganizerRole::cases() as $role) {
            foreach ($platformOnly as $permission) {
                $this->assertFalse($role->can($permission), "{$role->value} has {$permission->value}");
            }
        }
    }

    public function test_finance_can_approve_refunds_but_not_moderate(): void
    {
        $this->assertTrue(PlatformRole::Finance->can(Permission::RefundsApprove));
        $this->assertFalse(PlatformRole::Finance->can(Permission::EventsModerate));
        $this->assertFalse(PlatformRole::Support->can(Permission::RefundsApprove));
        $this->assertFalse(PlatformRole::Support->can(Permission::PlatformSettingsManage));
    }
}
