<?php

namespace App\Actions\Admin;

use App\Enums\OrganizerStatus;
use App\Models\Organizer;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Platform staff verify, suspend, reinstate or reject an organiser.
 * A reason is mandatory for anything other than verification.
 */
class ChangeOrganizerStatus
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Organizer $organizer, OrganizerStatus $to, ?string $reason = null): Organizer
    {
        Gate::forUser($actor)->authorize('changeStatus', $organizer);

        if ($organizer->status === $to) {
            return $organizer;
        }

        if ($to !== OrganizerStatus::Verified && blank($reason)) {
            throw new InvalidArgumentException('A reason is required to suspend or reject an organiser.');
        }

        return DB::transaction(function () use ($actor, $organizer, $to, $reason): Organizer {
            $from = $organizer->status;
            $organizer->status = $to;

            if ($to === OrganizerStatus::Verified) {
                $organizer->verified_at ??= now();
            }

            $organizer->save();

            $this->audit->record('organizer.status_changed', $organizer, [
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
            ], actor: $actor);

            return $organizer;
        });
    }
}
