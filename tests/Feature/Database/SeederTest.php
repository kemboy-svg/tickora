<?php

namespace Tests\Feature\Database;

use App\Enums\EventStatus;
use App\Enums\OrganizerRole;
use App\Enums\PlatformRole;
use App\Models\Category;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organizer;
use App\Models\PromoCode;
use App\Models\TicketType;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DecemberMauritiusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_development_seed_builds_a_usable_marketplace(): void
    {
        Storage::fake('public');
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

        // Demo orders add up exactly to each ticket type's sold count.
        foreach (TicketType::query()->get() as $type) {
            $inOrders = (int) DB::table('order_items')->where('ticket_type_id', $type->id)->sum('quantity');
            $this->assertSame($type->quantity_sold, $inOrders, "{$type->name} sold count matches its orders");
        }

        $amara = User::query()->where('email', 'amara@example.test')->firstOrFail();
        $this->assertGreaterThan(0, Order::query()->where('user_id', $amara->id)->count());
    }

    public function test_december_events_cover_the_cases_worth_testing(): void
    {
        Storage::fake('public');
        $this->seed(DecemberMauritiusSeeder::class);
        // Running it twice must not duplicate anything.
        $this->seed(DecemberMauritiusSeeder::class);

        $december = Event::query()
            ->where('starts_at', '>=', '2026-11-30 20:00:00')
            ->where('starts_at', '<', '2026-12-31 20:00:00')
            ->get();

        $this->assertCount(14, $december);
        $this->assertTrue($december->every(fn (Event $e): bool => $e->timezone === 'Indian/Mauritius' && $e->currency === 'MUR'));

        $listed = Event::query()->listed()->where('slug', 'like', '%-dec-2026')->count();
        $this->assertSame(12, $listed, 'one unlisted, one waiting for review');
        $this->assertSame(1, Event::query()->where('status', EventStatus::PendingReview)->where('slug', 'like', '%-dec-2026')->count());

        $nye = Event::query()->where('slug', 'new-years-eve-beach-party-2027-dec-2026')->firstOrFail();
        $this->assertSame('2026-12-31 16:00', $nye->starts_at->utc()->format('Y-m-d H:i'), '20:00 in Mauritius');
        $this->assertSame(18, $nye->min_age);

        $comedy = Event::query()->where('slug', 'kreol-comedy-christmas-special-dec-2026')->firstOrFail();
        $this->assertSame(0, $comedy->ticketTypes()->firstOrFail()->remaining(), 'sold out');

        $sunset = Event::query()->where('slug', 'sunset-sessions-le-morne-dec-2026')->firstOrFail();
        $this->assertTrue($sunset->sales_starts_at?->isFuture(), 'sales not open yet');

        $this->assertTrue(TicketType::query()->where('unit_amount_minor', 0)->exists(), 'free tickets');
        $this->assertSame(2, PromoCode::query()->whereIn('code', ['XMAS10', 'EARLYRUN'])->count());

        // Covers were generated and sold counts are backed by orders.
        $this->assertNotNull($nye->cover_image_key);
        Storage::disk('public')->assertExists((string) $nye->cover_image_key);

        foreach (TicketType::query()->get() as $type) {
            $this->assertSame($type->quantity_sold, (int) DB::table('order_items')->where('ticket_type_id', $type->id)->sum('quantity'));
        }
    }

    public function test_categories_can_be_seeded_repeatedly(): void
    {
        $this->seed(CategorySeeder::class);
        $this->seed(CategorySeeder::class);

        $this->assertSame(8, Category::query()->count());
    }
}
