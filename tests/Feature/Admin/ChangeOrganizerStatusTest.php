<?php

namespace Tests\Feature\Admin;

use App\Actions\Admin\ChangeOrganizerStatus;
use App\Enums\OrganizerStatus;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Organizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ChangeOrganizerStatusTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_platform_admins_verify_organisers(): void
    {
        $organizer = Organizer::factory()->pending()->create();
        $admin = $this->platformUser(PlatformRole::Admin);

        app(ChangeOrganizerStatus::class)->handle($admin, $organizer, OrganizerStatus::Verified);

        $this->assertSame(OrganizerStatus::Verified, $organizer->status);
        $this->assertNotNull($organizer->verified_at);
        $this->assertTrue($organizer->canSell());

        $log = AuditLog::query()->where('action', 'organizer.status_changed')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame('pending', $log->metadata['from'] ?? null);
        $this->assertSame('verified', $log->metadata['to'] ?? null);
    }

    public function test_suspension_requires_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ChangeOrganizerStatus::class)->handle(
            $this->platformUser(PlatformRole::Admin),
            $this->organizer(),
            OrganizerStatus::Suspended,
        );
    }

    public function test_finance_staff_and_organisers_cannot_change_status(): void
    {
        $organizer = Organizer::factory()->pending()->create();

        foreach ([$this->platformUser(PlatformRole::Finance), $this->ownerOf($organizer)] as $user) {
            try {
                app(ChangeOrganizerStatus::class)->handle($user, $organizer, OrganizerStatus::Verified);
                $this->fail('Status change was allowed.');
            } catch (AuthorizationException) {
                $this->assertSame(OrganizerStatus::Pending, $organizer->fresh()?->status);
            }
        }
    }

    public function test_suspended_organisers_cannot_sell_but_keep_their_workspace(): void
    {
        $organizer = $this->organizer();
        $owner = $this->ownerOf($organizer);

        app(ChangeOrganizerStatus::class)->handle(
            $this->platformUser(PlatformRole::Admin),
            $organizer,
            OrganizerStatus::Suspended,
            'Chargeback investigation',
        );

        $this->assertFalse($organizer->canSell());
        $this->assertTrue($owner->can('view', $organizer));
    }
}
