<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_secrets_are_scrubbed_from_metadata(): void
    {
        $log = app(AuditLogger::class)->record('payouts.account_changed', metadata: [
            'bank' => 'MCB',
            'account_number' => '000123456789',
            'nested' => ['api_key' => 'sk_live_123', 'last4' => '4417'],
            'qr_token' => 'TK1.abc',
            'Password' => 'hunter2',
        ]);

        $stored = AuditLog::query()->findOrFail($log->id)->metadata;

        $this->assertSame('MCB', $stored['bank'] ?? null);
        $this->assertSame('[redacted]', $stored['account_number'] ?? null);
        $this->assertSame('[redacted]', $stored['nested']['api_key'] ?? null);
        $this->assertSame('4417', $stored['nested']['last4'] ?? null);
        $this->assertSame('[redacted]', $stored['qr_token'] ?? null);
        $this->assertSame('[redacted]', $stored['Password'] ?? null);
    }

    public function test_the_actor_defaults_to_the_signed_in_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $log = app(AuditLogger::class)->record('profile.viewed');

        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertSame('user', $log->actor_type);
    }

    public function test_entries_without_a_user_are_attributed_to_the_system(): void
    {
        $log = app(AuditLogger::class)->record('reservations.expired', metadata: ['count' => 3]);

        $this->assertNull($log->actor_user_id);
        $this->assertSame('system', $log->actor_type);
    }

    public function test_the_organiser_is_inferred_from_the_subject(): void
    {
        $organizer = $this->organizer();
        $event = $this->publishedEventFor($organizer);

        $this->assertSame($organizer->id, app(AuditLogger::class)->record('event.published', $event)->organizer_id);
        $this->assertSame($organizer->id, app(AuditLogger::class)->record('organizer.updated', $organizer)->organizer_id);
        $this->assertNull(app(AuditLogger::class)->record('user.updated', User::factory()->create())->organizer_id);
    }

    public function test_audit_entries_cannot_be_edited(): void
    {
        $log = app(AuditLogger::class)->record('something.happened');

        $this->expectException(LogicException::class);

        $log->action = 'something.else';
        $log->save();
    }

    public function test_deleting_the_actor_keeps_the_entry(): void
    {
        $user = User::factory()->create();
        $log = app(AuditLogger::class)->record('something.happened', actor: $user);

        $user->delete();

        $fresh = AuditLog::query()->findOrFail($log->id);
        $this->assertNull($fresh->actor_user_id);
        $this->assertSame('something.happened', $fresh->action);
    }
}
