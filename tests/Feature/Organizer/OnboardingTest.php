<?php

namespace Tests\Feature\Organizer;

use App\Enums\OrganizerRole;
use App\Enums\OrganizerStatus;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_users_without_an_organiser_are_sent_to_onboarding(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/organizer/events')
            ->assertRedirect('/organizer/onboarding');
    }

    public function test_guests_must_sign_in(): void
    {
        $this->get('/organizer/onboarding')->assertRedirect('/login');
    }

    public function test_the_onboarding_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/organizer/onboarding')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organizer/Onboarding')
                ->where('hasOrganizers', false)
                ->has('currencies'));
    }

    public function test_a_user_creates_an_organiser_and_lands_in_its_workspace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/organizer/onboarding', [
                'name' => 'Island Beats Ltd',
                'country_code' => 'mu',
                'default_currency' => 'MUR',
            ])
            ->assertRedirect('/organizer/events');

        $organizer = Organizer::query()->where('name', 'Island Beats Ltd')->firstOrFail();

        $this->assertSame(OrganizerStatus::Pending, $organizer->status);
        $this->assertSame('MU', $organizer->country_code);
        $this->assertSame(OrganizerRole::Owner, $user->roleIn($organizer));

        $this->actingAs($user)
            ->get('/organizer/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organizer/events/Index')
                ->where('workspace.current.name', 'Island Beats Ltd')
                ->where('workspace.current.can_sell', false));
    }

    public function test_onboarding_validates_input(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/organizer/onboarding', [
                'name' => '',
                'country_code' => 'Mauritius',
                'default_currency' => 'GBP',
            ])
            ->assertSessionHasErrors(['name', 'country_code', 'default_currency']);
    }

    public function test_members_of_several_organisers_can_switch_but_only_to_their_own(): void
    {
        $first = $this->organizer();
        $user = $this->ownerOf($first);
        $second = $this->organizer();
        $this->addMember($second, $user);
        $foreign = $this->organizer();

        $this->actingAs($user)
            ->post('/organizer/switch', ['organizer' => $second->public_id])
            ->assertRedirect('/organizer/events');

        $this->actingAs($user)
            ->get('/organizer/events')
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.current.id', $second->public_id)
                ->has('workspace.available', 2));

        $this->actingAs($user)
            ->post('/organizer/switch', ['organizer' => $foreign->public_id])
            ->assertNotFound();
    }

    private function addMember(Organizer $organizer, User $user): void
    {
        OrganizerMember::factory()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $user->id,
            'role' => OrganizerRole::Manager,
        ]);
    }
}
