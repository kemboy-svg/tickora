<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Enums\TicketTypeStatus;
use App\Models\Event;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The event review lifecycle:
 *
 *   draft --submit--> pending_review --approve--> published
 *                          |
 *                          +--reject--> draft (with reviewer notes)
 *
 *   draft / pending_review / published --cancel--> cancelled
 *
 * Each step is authorised by EventPolicy and written to the audit trail.
 */
class ChangeEventStatus
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function submit(User $actor, Event $event): Event
    {
        Gate::forUser($actor)->authorize('submitForReview', $event);

        $problems = $this->readinessProblems($event);

        if ($problems !== []) {
            throw ValidationException::withMessages(['event' => $problems]);
        }

        return DB::transaction(function () use ($actor, $event): Event {
            $event->review_notes = null;
            $event->transitionTo(EventStatus::PendingReview);

            $this->audit->record('event.submitted_for_review', $event, actor: $actor);

            return $event;
        });
    }

    public function approve(User $moderator, Event $event): Event
    {
        Gate::forUser($moderator)->authorize('publish', $event);

        return DB::transaction(function () use ($moderator, $event): Event {
            $event->reviewed_by = $moderator->id;
            $event->review_notes = null;
            $event->transitionTo(EventStatus::Published);

            $this->audit->record('event.published', $event, actor: $moderator);

            return $event;
        });
    }

    public function reject(User $moderator, Event $event, string $notes): Event
    {
        Gate::forUser($moderator)->authorize('moderate', $event);

        if ($event->status !== EventStatus::PendingReview) {
            throw ValidationException::withMessages(['event' => 'Only events awaiting review can be sent back.']);
        }

        return DB::transaction(function () use ($moderator, $event, $notes): Event {
            $event->reviewed_by = $moderator->id;
            $event->review_notes = $notes;
            $event->transitionTo(EventStatus::Draft);

            $this->audit->record('event.rejected', $event, ['notes' => $notes], actor: $moderator);

            return $event;
        });
    }

    public function cancel(User $actor, Event $event, string $reason): Event
    {
        Gate::forUser($actor)->authorize('cancel', $event);

        return DB::transaction(function () use ($actor, $event, $reason): Event {
            $event->cancellation_reason = $reason;
            $event->transitionTo(EventStatus::Cancelled);

            $paidOrders = $event->orders()
                ->whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded])
                ->count();

            // Refunding paid orders is handled by the refunds workflow; the
            // count is recorded so finance can reconcile against it.
            $this->audit->record('event.cancelled', $event, [
                'reason' => $reason,
                'paid_orders' => $paidOrders,
            ], actor: $actor);

            return $event;
        });
    }

    public function delete(User $actor, Event $event): void
    {
        Gate::forUser($actor)->authorize('delete', $event);

        $paths = $event->images()->get(['disk', 'path']);

        DB::transaction(function () use ($actor, $event): void {
            $this->audit->record('event.deleted', $event, ['title' => $event->title], actor: $actor);
            $event->ticketTypes()->delete();
            $event->staffAssignments()->delete();
            $event->delete();
        });

        // Files go only after the rows are gone for good.
        foreach ($paths as $image) {
            Storage::disk($image->disk)->delete($image->path);
        }
    }

    /**
     * What stops a draft from being submitted, in plain language.
     *
     * @return list<string>
     */
    public function readinessProblems(Event $event): array
    {
        $problems = [];

        if ($event->venue_id === null) {
            $problems[] = 'Add a venue.';
        }

        if ($event->starts_at->isPast()) {
            $problems[] = 'The start time is in the past.';
        }

        if (! $event->ticketTypes()->where('status', TicketTypeStatus::Active)->exists()) {
            $problems[] = 'Add at least one ticket type that is on sale.';
        }

        if (blank($event->description)) {
            $problems[] = 'Add a description.';
        }

        return $problems;
    }
}
