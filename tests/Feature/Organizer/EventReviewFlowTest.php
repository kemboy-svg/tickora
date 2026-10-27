<?php

namespace Tests\Feature\Organizer;

use App\Enums\EventStatus;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Phase 2 exit criterion: an organiser can get an event approved and live.
 */
class EventReviewFlowTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private function moderator(): User
    {
        $user = $this->platformUser(PlatformRole::Admin);
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    private function readyDraft(Organizer $organizer): Event
    {
        $event = Event::factory()->forOrganizer($organizer)->create();
        TicketType::factory()->create(['event_id' => $event->id]);

        return $event;
    }

    public function test_draft_to_review_to_published(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);
        $event = $this->readyDraft($organizer);

        $this->actingAs($owner)
            ->post('/organizer/events/'.$event->public_id.'/submit')
            ->assertRedirect();
        $this->assertSame(EventStatus::PendingReview, $event->fresh()?->status);

        // Not public while under review.
        $this->get('/events/'.$event->slug)->assertNotFound();

        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->get('/admin/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/events/Index')
                ->has('events.data', 1)
                ->where('events.data.0.id', $event->public_id));

        $this->actingAs($moderator)
            ->post('/admin/events/'.$event->public_id.'/approve')
            ->assertRedirect('/admin/events');

        $event->refresh();
        $this->assertSame(EventStatus::Published, $event->status);
        $this->assertSame($moderator->id, $event->reviewed_by);
        $this->assertNotNull($event->published_at);
        $this->assertTrue(AuditLog::query()->where('action', 'event.published')->exists());

        $this->get('/events/'.$event->slug)->assertOk();
    }

    public function test_incomplete_drafts_cannot_be_submitted(): void
    {
        $organizer = $this->organizer();
        $event = Event::factory()->forOrganizer($organizer)->create(['description' => null]);

        $this->actingAs($this->ownerOf($organizer))
            ->post('/organizer/events/'.$event->public_id.'/submit')
            ->assertSessionHasErrors('event');

        $this->assertSame(EventStatus::Draft, $event->fresh()?->status);
    }

    public function test_moderators_send_events_back_with_notes(): void
    {
        $organizer = $this->organizer();
        $event = $this->readyDraft($organizer);
        $event->transitionTo(EventStatus::PendingReview);
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->post('/admin/events/'.$event->public_id.'/reject', ['notes' => 'short'])
            ->assertSessionHasErrors('notes');

        $this->actingAs($moderator)
            ->post('/admin/events/'.$event->public_id.'/reject', ['notes' => 'Please add the full venue address.'])
            ->assertRedirect('/admin/events');

        $event->refresh();
        $this->assertSame(EventStatus::Draft, $event->status);
        $this->assertSame('Please add the full venue address.', $event->review_notes);

        $this->actingAs($this->ownerOf($organizer))
            ->get('/organizer/events/'.$event->public_id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.review_notes', 'Please add the full venue address.'));
    }

    public function test_events_of_unverified_organisers_cannot_be_approved(): void
    {
        $organizer = Organizer::factory()->pending()->create();
        $event = $this->readyDraft($organizer);
        $event->transitionTo(EventStatus::PendingReview);

        $this->actingAs($this->moderator())
            ->post('/admin/events/'.$event->public_id.'/approve')
            ->assertForbidden();
    }

    public function test_the_admin_area_requires_two_factor_and_a_platform_role(): void
    {
        $adminWithout2fa = $this->platformUser(PlatformRole::Admin);
        $this->actingAs($adminWithout2fa)->get('/admin/events')->assertRedirect(route('security.edit'));

        $organizerOwner = $this->ownerOf($this->organizer());
        $organizerOwner->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->actingAs($organizerOwner)->get('/admin/events')->assertForbidden();
        $this->actingAs($organizerOwner)->get('/admin/organizers')->assertForbidden();
    }

    public function test_admins_verify_organisers_and_need_a_reason_to_suspend(): void
    {
        $organizer = Organizer::factory()->pending()->create();
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->get('/admin/organizers')
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/organizers/Index')
                ->where('organizers.data.0.id', $organizer->public_id));

        $this->actingAs($moderator)
            ->patch('/admin/organizers/'.$organizer->public_id.'/status', ['status' => 'verified'])
            ->assertRedirect();
        $this->assertTrue($organizer->fresh()?->canSell());

        $this->actingAs($moderator)
            ->patch('/admin/organizers/'.$organizer->public_id.'/status', ['status' => 'suspended'])
            ->assertSessionHasErrors('reason');
    }
}
