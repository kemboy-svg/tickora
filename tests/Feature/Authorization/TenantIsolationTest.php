<?php

namespace Tests\Feature\Authorization;

use App\Enums\OrganizerRole;
use App\Enums\UserStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrganizerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Organisers must never reach another organiser's events, orders,
 * attendees or payout data — through policies or through queries.
 */
class TenantIsolationTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_an_owner_cannot_act_on_another_organisers_event(): void
    {
        $mine = $this->organizer();
        $theirs = $this->organizer();
        $owner = $this->ownerOf($mine);
        $foreignEvent = $this->publishedEventFor($theirs);

        foreach (['view', 'update', 'cancel', 'manageTicketTypes', 'viewOrders', 'viewAttendees', 'exportAttendees', 'viewReports', 'manageStaff', 'scan'] as $ability) {
            $this->assertFalse($owner->can($ability, $foreignEvent), "owner could [{$ability}] a foreign event");
        }
    }

    public function test_an_owner_cannot_manage_another_organiser(): void
    {
        $mine = $this->organizer();
        $theirs = $this->organizer();
        $owner = $this->ownerOf($mine);

        foreach (['view', 'update', 'manageTeam', 'createEvent', 'viewReports', 'viewPayouts', 'managePayouts'] as $ability) {
            $this->assertTrue($owner->can($ability, $mine), "owner could not [{$ability}] their own organiser");
            $this->assertFalse($owner->can($ability, $theirs), "owner could [{$ability}] a foreign organiser");
        }
    }

    public function test_scoped_queries_only_return_the_members_own_rows(): void
    {
        $mine = $this->organizer();
        $theirs = $this->organizer();
        $manager = $this->memberOf($mine, OrganizerRole::Manager);

        $myEvent = $this->publishedEventFor($mine);
        $theirEvent = $this->publishedEventFor($theirs);
        $myOrder = Order::factory()->create(['event_id' => $myEvent->id]);
        Order::factory()->create(['event_id' => $theirEvent->id]);

        $this->assertSame([$myEvent->id], Event::query()->visibleTo($manager)->pluck('id')->all());
        $this->assertSame([$myOrder->id], Order::query()->visibleTo($manager)->pluck('id')->all());

        // Looking a foreign record up by its public ID through the scope finds nothing.
        $this->assertNull(Event::query()->visibleTo($manager)->where('public_id', $theirEvent->public_id)->first());
    }

    public function test_a_user_in_two_organisers_sees_both_but_with_separate_roles(): void
    {
        $a = $this->organizer();
        $b = $this->organizer();
        $user = $this->memberOf($a, OrganizerRole::Manager);
        OrganizerMember::factory()->staff()->create(['organizer_id' => $b->id, 'user_id' => $user->id]);

        $eventA = $this->publishedEventFor($a);
        $eventB = $this->publishedEventFor($b);

        $this->assertEqualsCanonicalizing([$eventA->id, $eventB->id], Event::query()->visibleTo($user)->pluck('id')->all());

        $this->assertTrue($user->can('viewOrders', $eventA));
        $this->assertFalse($user->can('viewOrders', $eventB), 'staff role in B must not inherit manager rights from A');
    }

    public function test_revoked_members_lose_access_immediately(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $manager = $this->memberOf($organizer, OrganizerRole::Manager);

        $this->assertTrue($manager->can('view', $event));

        OrganizerMember::query()
            ->where('user_id', $manager->id)
            ->update(['status' => 'revoked', 'revoked_at' => now()]);

        $manager = $manager->fresh() ?? $manager;

        $this->assertFalse($manager->can('view', $event));
        $this->assertSame([], Event::query()->visibleTo($manager)->pluck('id')->all());
    }

    public function test_suspended_users_are_denied_everything(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $owner = $this->ownerOf($organizer);

        $owner->forceFill(['status' => UserStatus::Suspended])->save();

        $this->assertFalse($owner->can('view', $organizer));
        $this->assertFalse($owner->can('update', $event));
        $this->assertFalse($owner->can('scan', $event));
    }

    public function test_members_of_a_rejected_organiser_cannot_use_its_workspace(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $owner = $this->ownerOf($organizer);

        $organizer->forceFill(['status' => 'rejected'])->save();
        $event->refresh();

        $this->assertFalse($owner->can('view', $organizer));
        $this->assertFalse($owner->can('view', $event));
        $this->assertFalse($owner->can('viewOrders', $event));
    }

    public function test_customers_with_no_membership_have_no_organiser_access(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $customer = User::factory()->create();

        $this->assertFalse($customer->can('view', $organizer));
        $this->assertFalse($customer->can('view', $event));
        $this->assertSame([], Event::query()->visibleTo($customer)->pluck('id')->all());
    }
}
