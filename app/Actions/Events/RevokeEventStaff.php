<?php

namespace App\Actions\Events;

use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Gate;

class RevokeEventStaff
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Returns false when there was no current assignment to revoke.
     */
    public function handle(User $actor, Event $event, User $staff): bool
    {
        Gate::forUser($actor)->authorize('manageStaff', $event);

        $assignment = EventStaffAssignment::query()
            ->where('event_id', $event->id)
            ->where('user_id', $staff->id)
            ->whereNull('revoked_at')
            ->first();

        if ($assignment === null) {
            return false;
        }

        $assignment->revoked_at = now();
        $assignment->revoked_by = $actor->id;
        $assignment->save();

        $this->audit->record('event.staff_revoked', $event, [
            'staff_user_id' => $staff->id,
        ], actor: $actor);

        return true;
    }
}
