<?php

namespace Tests\Feature\Organizers;

use App\Actions\Events\AssignEventStaff;
use App\Actions\Organizers\AddOrganizerMember;
use App\Actions\Organizers\ChangeMemberRole;
use App\Actions\Organizers\CreateOrganizer;
use App\Actions\Organizers\RemoveOrganizerMember;
use App\Enums\MembershipStatus;
use App\Enums\OrganizerRole;
use App\Enums\OrganizerStatus;
use App\Exceptions\UnsupportedCurrencyException;
use App\Models\AuditLog;
use App\Models\EventStaffAssignment;
use App\Models\OrganizerMember;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_creating_an_organiser_makes_the_user_its_owner_pending_verification(): void
    {
        $user = User::factory()->create();

        $organizer = app(CreateOrganizer::class)->handle($user, [
            'name' => 'Island Beats Ltd',
            'country_code' => 'mu',
            'default_currency' => 'mur',
        ]);

        $this->assertSame(OrganizerStatus::Pending, $organizer->status);
        $this->assertSame('island-beats-ltd', $organizer->slug);
        $this->assertSame('MUR', $organizer->default_currency);
        $this->assertSame(OrganizerRole::Owner, $user->roleIn($organizer));
        $this->assertFalse($organizer->canSell());
        $this->assertTrue(AuditLog::query()->where('action', 'organizer.created')->where('organizer_id', $organizer->id)->exists());
    }

    public function test_organisers_must_use_an_enabled_currency(): void
    {
        $this->expectException(UnsupportedCurrencyException::class);

        app(CreateOrganizer::class)->handle(User::factory()->create(), [
            'name' => 'Somewhere Ltd',
            'country_code' => 'GB',
            'default_currency' => 'GBP',
        ]);
    }

    public function test_slugs_stay_unique(): void
    {
        $first = app(CreateOrganizer::class)->handle(User::factory()->create(), [
            'name' => 'Same Name', 'country_code' => 'MU', 'default_currency' => 'MUR',
        ]);
        $second = app(CreateOrganizer::class)->handle(User::factory()->create(), [
            'name' => 'Same Name', 'country_code' => 'MU', 'default_currency' => 'MUR',
        ]);

        $this->assertNotSame($first->slug, $second->slug);
    }

    public function test_the_owner_adds_members_and_it_is_audited(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $newcomer = User::factory()->create();

        $membership = app(AddOrganizerMember::class)->handle($owner, $organizer, $newcomer, OrganizerRole::Manager);

        $this->assertSame(OrganizerRole::Manager, $membership->role);
        $this->assertSame($owner->id, $membership->invited_by);

        $log = AuditLog::query()->where('action', 'organizer.member_added')->firstOrFail();
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame($organizer->id, $log->organizer_id);
        // jsonb does not preserve key order, so compare as a map.
        $this->assertEquals(['user_id' => $newcomer->id, 'role' => 'manager'], $log->metadata);
    }

    public function test_managers_cannot_change_the_team(): void
    {
        $organizer = $this->organizer();
        $manager = $this->memberOf($organizer, OrganizerRole::Manager);

        $this->expectException(AuthorizationException::class);

        app(AddOrganizerMember::class)->handle($manager, $organizer, User::factory()->create(), OrganizerRole::Staff);
    }

    public function test_nobody_can_be_granted_ownership_directly(): void
    {
        $organizer = $this->organizer();

        $this->expectException(InvalidArgumentException::class);

        app(AddOrganizerMember::class)->handle($this->ownerOf($organizer), $organizer, User::factory()->create(), OrganizerRole::Owner);
    }

    public function test_the_database_allows_only_one_active_owner(): void
    {
        $organizer = $this->organizer();

        $this->expectException(QueryException::class);

        OrganizerMember::factory()->create([
            'organizer_id' => $organizer->id,
            'role' => OrganizerRole::Owner,
        ]);
    }

    public function test_changing_a_role_is_audited_with_before_and_after(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $user = $this->memberOf($organizer, OrganizerRole::Staff);
        $membership = OrganizerMember::query()->where('user_id', $user->id)->firstOrFail();

        app(ChangeMemberRole::class)->handle($owner, $membership, OrganizerRole::Manager);

        $this->assertSame(OrganizerRole::Manager, $user->roleIn($organizer));
        $this->assertEquals(
            ['user_id' => $user->id, 'from' => 'staff', 'to' => 'manager'],
            AuditLog::query()->where('action', 'organizer.member_role_changed')->firstOrFail()->metadata,
        );
    }

    public function test_the_owners_role_cannot_be_changed(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $membership = OrganizerMember::query()->where('user_id', $owner->id)->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        app(ChangeMemberRole::class)->handle($owner, $membership, OrganizerRole::Manager);
    }

    public function test_removing_a_member_also_revokes_their_gate_assignments(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $event = $this->publishedEventFor($organizer);
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);
        app(AssignEventStaff::class)->handle($owner, $event, $staff);

        $this->assertTrue($staff->can('scan', $event));

        $membership = OrganizerMember::query()->where('user_id', $staff->id)->firstOrFail();
        app(RemoveOrganizerMember::class)->handle($owner, $membership);

        $this->assertSame(MembershipStatus::Revoked, $membership->fresh()?->status);
        $this->assertNotNull(EventStaffAssignment::query()->where('user_id', $staff->id)->firstOrFail()->revoked_at);
        $this->assertFalse($staff->can('scan', $event));
        $this->assertSame(1, AuditLog::query()->where('action', 'organizer.member_removed')->firstOrFail()->metadata['staff_assignments_revoked'] ?? null);
    }

    public function test_removed_members_can_be_reinstated(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $user = $this->memberOf($organizer, OrganizerRole::Manager);
        $membership = OrganizerMember::query()->where('user_id', $user->id)->firstOrFail();

        app(RemoveOrganizerMember::class)->handle($owner, $membership);
        app(AddOrganizerMember::class)->handle($owner, $organizer, $user, OrganizerRole::Staff);

        $this->assertSame(1, OrganizerMember::query()->where('user_id', $user->id)->count());
        $this->assertSame(OrganizerRole::Staff, $user->roleIn($organizer));
    }

    public function test_the_owner_cannot_be_removed(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $membership = OrganizerMember::query()->where('user_id', $owner->id)->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        app(RemoveOrganizerMember::class)->handle($owner, $membership);
    }
}
