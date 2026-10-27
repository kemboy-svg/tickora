<?php

namespace Tests\Feature\Authorization;

use App\Enums\EventStatus;
use App\Enums\OrganizerRole;
use App\Enums\PlatformRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class EventPolicyTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_organisers_submit_events_but_only_platform_moderators_publish_them(): void
    {
        $organizer = $this->organizer();
        $manager = $this->memberOf($organizer, OrganizerRole::Manager);
        $moderator = $this->platformUser(PlatformRole::Admin);
        $draft = Event::factory()->forOrganizer($organizer)->create();

        $this->assertTrue($manager->can('submitForReview', $draft));
        $this->assertFalse($moderator->can('submitForReview', $draft));

        $draft->transitionTo(EventStatus::PendingReview);

        $this->assertFalse($manager->can('publish', $draft));
        $this->assertTrue($moderator->can('publish', $draft));
    }

    public function test_events_of_unverified_organisers_cannot_be_published(): void
    {
        $organizer = Event::factory()->pendingReview()->create()->organizer;
        $organizer->forceFill(['status' => 'pending'])->save();
        $event = $organizer->events()->firstOrFail();

        $this->assertFalse($this->platformUser(PlatformRole::Admin)->can('publish', $event));
    }

    public function test_finance_staff_cannot_moderate_events(): void
    {
        $event = Event::factory()->pendingReview()->create();

        $this->assertFalse($this->platformUser(PlatformRole::Finance)->can('publish', $event));
        $this->assertTrue($this->platformUser(PlatformRole::Support)->can('moderate', $event));
    }

    public function test_gate_staff_get_scan_access_only_to_assigned_events(): void
    {
        $organizer = $this->organizer();
        $assigned = $this->publishedEventFor($organizer);
        $other = $this->publishedEventFor($organizer);
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);

        $this->assertFalse($staff->can('scan', $assigned));

        $this->assignStaff($assigned, $staff);

        $this->assertTrue($staff->can('scan', $assigned));
        $this->assertTrue($staff->can('view', $assigned));
        $this->assertFalse($staff->can('scan', $other));
        $this->assertFalse($staff->can('view', $other));
    }

    public function test_gate_staff_never_see_sales_or_attendee_data(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);
        $this->assignStaff($event, $staff);

        foreach (['update', 'viewOrders', 'viewAttendees', 'exportAttendees', 'viewReports', 'manageStaff', 'manageTicketTypes'] as $ability) {
            $this->assertFalse($staff->can($ability, $event), "staff could [{$ability}]");
        }

        $this->assertFalse($staff->can('viewPayouts', $organizer));
    }

    public function test_revoked_assignments_stop_scanning(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);
        $staff = $this->memberOf($organizer, OrganizerRole::Staff);
        $assignment = $this->assignStaff($event, $staff);

        $assignment->forceFill(['revoked_at' => now()])->save();

        $this->assertFalse($staff->can('scan', $event));
    }

    public function test_owners_and_managers_can_scan_without_an_assignment(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);

        $this->assertTrue($this->ownerOf($organizer)->can('scan', $event));
        $this->assertTrue($this->memberOf($organizer, OrganizerRole::Manager)->can('scan', $event));
    }

    public function test_nobody_scans_unpublished_events_and_platform_staff_never_scan(): void
    {
        $organizer = $this->organizer();
        $draft = Event::factory()->forOrganizer($organizer)->create();
        $cancelled = Event::factory()->forOrganizer($organizer)->cancelled()->create();
        $live = $this->publishedEventFor($organizer);
        $owner = $this->ownerOf($organizer);

        $this->assertFalse($owner->can('scan', $draft));
        $this->assertFalse($owner->can('scan', $cancelled));
        $this->assertFalse($this->platformUser(PlatformRole::Admin)->can('scan', $live));
        $this->assertFalse(User::factory()->create()->can('scan', $live));
    }

    public function test_only_unsold_drafts_can_be_deleted(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $draft = Event::factory()->forOrganizer($organizer)->create();
        $published = $this->publishedEventFor($organizer);
        $draftWithOrder = Event::factory()->forOrganizer($organizer)->create();
        Order::factory()->pendingPayment()->create(['event_id' => $draftWithOrder->id]);

        $this->assertTrue($owner->can('delete', $draft));
        $this->assertFalse($owner->can('delete', $published));
        $this->assertFalse($owner->can('delete', $draftWithOrder));
    }

    public function test_cancelled_events_are_frozen(): void
    {
        $organizer = $this->organizer();
        $event = Event::factory()->forOrganizer($organizer)->cancelled()->create();
        $owner = $this->ownerOf($organizer);

        $this->assertFalse($owner->can('update', $event));
        $this->assertFalse($owner->can('cancel', $event));
        $this->assertFalse($owner->can('manageTicketTypes', $event));
        $this->assertTrue($owner->can('viewOrders', $event), 'history stays readable');
    }
}
