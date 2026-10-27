<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Enums\OrganizerRole;
use App\Enums\OrganizerStatus;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates a verified organiser whose owner is also an active "owner" member,
 * mirroring what the CreateOrganizer action does.
 *
 * @extends Factory<Organizer>
 */
class OrganizerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'owner_user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => fake()->sentence(12),
            'country_code' => 'MU',
            'default_currency' => 'MUR',
            'payout_currency' => 'MUR',
            'status' => OrganizerStatus::Verified,
            'support_email' => fake()->companyEmail(),
            'verified_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Organizer $organizer): void {
            $exists = OrganizerMember::query()
                ->where('organizer_id', $organizer->id)
                ->where('user_id', $organizer->owner_user_id)
                ->exists();

            if (! $exists) {
                $member = new OrganizerMember;
                $member->organizer_id = $organizer->id;
                $member->user_id = $organizer->owner_user_id;
                $member->role = OrganizerRole::Owner;
                $member->status = MembershipStatus::Active;
                $member->joined_at = now();
                $member->save();
            }
        });
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizerStatus::Pending,
            'verified_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => OrganizerStatus::Suspended]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizerStatus::Rejected,
            'verified_at' => null,
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (): array => ['owner_user_id' => $user->id]);
    }
}
