<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrganizerRole;
use App\Enums\PlatformRole;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Every link in the app's navigation opens a page for the people who see
 * it — no redirects to unrelated pages, no errors.
 */
class NavigationTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
    }

    private function confirmPassword(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function personas(): array
    {
        $everyone = ['/', '/events', '/dashboard', '/settings/profile', '/settings/security', '/settings/appearance'];

        return [
            'customer' => ['customer', [...$everyone, '/organizer/onboarding']],
            'owner' => ['owner', [...$everyone, '/organizer/dashboard', '/organizer/events', '/organizer/events/create', '/organizer/promo-codes', '/organizer/onboarding']],
            'manager' => ['manager', [...$everyone, '/organizer/dashboard', '/organizer/events', '/organizer/events/create', '/organizer/promo-codes']],
            'staff' => ['staff', [...$everyone, '/organizer/events']],
            'admin' => ['admin', [...$everyone, '/admin', '/admin/events', '/admin/organizers']],
            'finance' => ['finance', [...$everyone, '/admin']],
        ];
    }

    private function persona(string $name): User
    {
        $organizer = $this->organizer();
        $this->publishedEventFor($organizer);

        return match ($name) {
            'customer' => User::factory()->create(),
            'owner' => $this->ownerOf($organizer),
            'manager' => $this->memberOf($organizer, OrganizerRole::Manager),
            'staff' => $this->memberOf($organizer, OrganizerRole::Staff),
            'admin' => $this->platformUser(PlatformRole::Admin),
            default => $this->platformUser(PlatformRole::Finance),
        };
    }

    /**
     * @param  list<string>  $links
     */
    #[DataProvider('personas')]
    public function test_every_navigation_link_opens(string $persona, array $links): void
    {
        config(['tickora.require_two_factor' => false]);
        $user = $this->persona($persona);
        $this->confirmPassword();

        foreach ($links as $link) {
            $this->actingAs($user)->get($link)->assertOk();
        }
    }

    public function test_staff_land_on_their_events_instead_of_the_sales_overview(): void
    {
        $staff = $this->persona('staff');

        $this->actingAs($staff)->get('/organizer')->assertRedirect('/organizer/dashboard');
        $this->actingAs($staff)->get('/organizer/dashboard')->assertRedirect(route('organizer.events.index'));
    }

    public function test_the_admin_area_explains_itself_when_two_factor_is_enforced(): void
    {
        config(['tickora.require_two_factor' => true]);
        $admin = $this->persona('admin');

        $this->actingAs($admin)->get('/admin/events')
            ->assertRedirect(route('security.edit'))
            ->assertSessionHas('url.intended', url('/admin/events'));

        $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin)->get('/admin/events')->assertOk();
        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_people_without_a_platform_role_cannot_open_the_admin_overview(): void
    {
        config(['tickora.require_two_factor' => false]);

        $this->actingAs($this->persona('owner'))->get('/admin')->assertForbidden();
        $this->actingAs($this->persona('customer'))->get('/admin/events')->assertForbidden();
    }

    public function test_the_sidebar_flags_match_the_persona(): void
    {
        $this->actingAs($this->persona('finance'))->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('nav.platform', true)
                ->where('nav.moderation', false)
                ->where('nav.organizer', false));
    }
}
