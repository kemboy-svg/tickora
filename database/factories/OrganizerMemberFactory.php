<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Enums\OrganizerRole;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizerMember>
 */
class OrganizerMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organizer_id' => Organizer::factory(),
            'user_id' => User::factory(),
            'role' => OrganizerRole::Manager,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ];
    }

    public function manager(): static
    {
        return $this->state(fn (): array => ['role' => OrganizerRole::Manager]);
    }

    public function staff(): static
    {
        return $this->state(fn (): array => ['role' => OrganizerRole::Staff]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }

    public function invited(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Invited,
            'joined_at' => null,
        ]);
    }
}
