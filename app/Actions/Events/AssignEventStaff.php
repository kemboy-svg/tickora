<?php

namespace App\Actions\Events;

use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Give a member of the event's organiser gate-scanning access to this
 * event. Only existing, active members can be assigned, so staff are
 * always individual, accountable accounts — never shared logins.
 */
class AssignEventStaff
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Event $event, User $staff): EventStaffAssignment
    {
        Gate::forUser($actor)->authorize('manageStaff', $event);

        if (! $staff->isActive() || $staff->membershipFor($event->organizer) === null) {
            throw new InvalidArgumentException('Only active members of this organiser can be assigned as event staff.');
        }

        return DB::transaction(function () use ($actor, $event, $staff): EventStaffAssignment {
            $assignment = EventStaffAssignment::query()
                ->where('event_id', $event->id)
                ->where('user_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($assignment !== null && $assignment->isCurrent()) {
                return $assignment;
            }

            $assignment ??= new EventStaffAssignment;
            $assignment->event_id = $event->id;
            $assignment->user_id = $staff->id;
            $assignment->assigned_by = $actor->id;
            $assignment->revoked_at = null;
            $assignment->revoked_by = null;
            $assignment->save();

            $this->audit->record('event.staff_assigned', $event, [
                'staff_user_id' => $staff->id,
            ], actor: $actor);

            return $assignment;
        });
    }
}
