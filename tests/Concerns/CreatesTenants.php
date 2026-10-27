<?php

namespace Tests\Concerns;

use App\Enums\OrganizerRole;
use App\Enums\PlatformRole;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\User;

trait CreatesTenants
{
    protected function organizer(): Organizer
    {
        return Organizer::factory()->create();
    }

    protected function ownerOf(Organizer $organizer): User
    {
        return User::query()->findOrFail($organizer->owner_user_id);
    }

    protected function memberOf(Organizer $organizer, OrganizerRole $role): User
    {
        $user = User::factory()->create();

        OrganizerMember::factory()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    protected function platformUser(PlatformRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['platform_role' => $role])->save();

        return $user;
    }

    protected function publishedEventFor(Organizer $organizer): Event
    {
        return Event::factory()->forOrganizer($organizer)->published()->create();
    }

    protected function assignStaff(Event $event, User $user): EventStaffAssignment
    {
        $assignment = new EventStaffAssignment;
        $assignment->event_id = $event->id;
        $assignment->user_id = $user->id;
        $assignment->save();

        return $assignment;
    }
}
