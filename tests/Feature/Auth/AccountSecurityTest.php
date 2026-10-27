<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_suspended_user_is_signed_out_on_their_next_request(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_active_users_are_unaffected(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
    }

    public function test_status_and_platform_role_cannot_be_mass_assigned(): void
    {
        $user = new User([
            'name' => 'Mallory',
            'email' => 'mallory@example.test',
            'password' => 'password',
        ]);

        $this->assertFalse($user->isFillable('status'));
        $this->assertFalse($user->isFillable('platform_role'));
    }

    public function test_privileged_areas_require_two_factor_authentication(): void
    {
        Route::middleware(['web', 'auth', 'two-factor'])->get('/_test/privileged', fn () => 'ok');

        $user = User::factory()->create();

        $this->actingAs($user)->get('/_test/privileged')->assertRedirect(route('security.edit'));
        $this->actingAs($user)->getJson('/_test/privileged')->assertForbidden();

        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user->fresh() ?? $user)->get('/_test/privileged')->assertOk()->assertSee('ok');
    }
}
