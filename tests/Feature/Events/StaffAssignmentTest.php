<?php

namespace Tests\Feature\Events;

use App\Actions\Events\AssignEventStaff;
use App\Actions\Events\RevokeEventStaff;
use App\Enums\OrganizerRole;
use App\Models\AuditLog;
use App\Models\EventStaffAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class StaffAssignmentTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_managers_assign_members_as_gate_staff(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $manager = $this->memberOf($organizer, OrganizerRole::Manager);
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);

        $assignment = app(AssignEventStaff::class)->handle($manager, $event, $staff);

        $this->assertTrue($assignment->isCurrent());
        $this->assertSame($manager->id, $assignment->assigned_by);
        $this->assertTrue($staff->can('scan', $event));
        $this->assertTrue(AuditLog::query()->where('action', 'event.staff_assigned')->exists());
    }

    public function test_assigning_twice_keeps_a_single_assignment(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $owner = $this->ownerOf($organizer);
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);

        app(AssignEventStaff::class)->handle($owner, $event, $staff);
        app(AssignEventStaff::class)->handle($owner, $event, $staff);

        $this->assertSame(1, EventStaffAssignment::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'event.staff_assigned')->count());
    }

    public function test_outsiders_cannot_be_assigned(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $outsider = $this->memberOf($this->organizer(), OrganizerRole::Staff);

        $this->expectException(InvalidArgumentException::class);

        app(AssignEventStaff::class)->handle($this->ownerOf($organizer), $event, $outsider);
    }

    public function test_gate_staff_cannot_assign_others(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);
        $this->assignStaff($event, $staff);

        $this->expectException(AuthorizationException::class);

        app(AssignEventStaff::class)->handle($staff, $event, $this->memberOf($organizer, OrganizerRole::Staff));
    }

    public function test_revoking_and_reassigning(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $owner = $this->ownerOf($organizer);
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);

        app(AssignEventStaff::class)->handle($owner, $event, $staff);

        $this->assertTrue(app(RevokeEventStaff::class)->handle($owner, $event, $staff));
        $this->assertFalse($staff->can('scan', $event));
        $this->assertFalse(app(RevokeEventStaff::class)->handle($owner, $event, $staff), 'nothing left to revoke');

        app(AssignEventStaff::class)->handle($owner, $event, $staff);

        $this->assertTrue($staff->can('scan', $event));
        $this->assertSame(1, EventStaffAssignment::query()->count(), 'the row is reused, keeping one record per person and event');
    }

    public function test_suspended_users_cannot_be_assigned(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);
        $staff->forceFill(['status' => 'suspended'])->save();

        $this->expectException(InvalidArgumentException::class);

        app(AssignEventStaff::class)->handle($this->ownerOf($organizer), $event, User::query()->findOrFail($staff->id));
    }
}
