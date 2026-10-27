<?php

namespace Tests\Feature\Database;

use App\Enums\EventStatus;
use App\Enums\OrganizerRole;
use App\Enums\PlatformRole;
use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_development_seed_builds_a_usable_marketplace(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(8, Category::query()->count());

        $kaz = Organizer::query()->where('slug', 'kaz-productions')->firstOrFail();
        $this->assertTrue($kaz->canSell());

        $owner = User::query()->where('email', 'kavish@kazproductions.test')->firstOrFail();
        $staff = User::query()->where('email', 'priya@kazproductions.test')->firstOrFail();
        $admin = User::query()->where('email', 'admin@tickora.test')->firstOrFail();

        $this->assertSame(OrganizerRole::Owner, $owner->roleIn($kaz));
        $this->assertSame(OrganizerRole::Staff, $staff->roleIn($kaz));
        $this->assertSame(PlatformRole::Admin, $admin->platform_role);

        $sega = Event::query()->where('slug', 'sega-nights-live-on-the-waterfront')->firstOrFail();
        $this->assertSame(EventStatus::Published, $sega->status);
        $this->assertSame(4, $sega->ticketTypes()->count());
        $this->assertSame('2026-11-14 15:00:00', $sega->starts_at->utc()->format('Y-m-d H:i:s'), '19:00 in Mauritius is 15:00 UTC');
        $this->assertTrue($staff->can('scan', $sega));

        $jazz = Event::query()->where('slug', 'afro-jazz-sessions')->firstOrFail();
        $this->assertSame('KES', $jazz->currency);
        $this->assertFalse($owner->can('view', $jazz), 'organisers are isolated in seed data too');
    }

    public function test_categories_can_be_seeded_repeatedly(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seed(CategorySeeder::class);

        $this->assertSame(8, Category::query()->count());
    }
}
