<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\OrganizerRole;
use App\Enums\PlatformRole;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Local development data matching the design mockups. Every account's
 * password is "password". Never run in production.
 *
 * Model events stay on: public IDs are assigned in a creating hook.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CategorySeeder::class);

        if (app()->isProduction()) {
            return;
        }

        // --- People -------------------------------------------------------
        $admin = $this->user('Tickora Admin', 'admin@tickora.test');
        $admin->forceFill(['platform_role' => PlatformRole::Admin])->save();

        $finance = $this->user('Fatima Finance', 'finance@tickora.test');
        $finance->forceFill(['platform_role' => PlatformRole::Finance])->save();

        $owner = $this->user('Kavish Purmessur', 'kavish@kazproductions.test');
        $manager = $this->user('Nadia Lam', 'nadia@kazproductions.test');
        $staff = $this->user('Priya Naidoo', 'priya@kazproductions.test');
        $this->user('Amara Okonkwo', 'amara@example.test');

        $nairobiOwner = $this->user('Wanjiru Kamau', 'wanjiru@nairobilive.test');

        // --- Organisers ---------------------------------------------------
        $kaz = Organizer::factory()->ownedBy($owner)->create([
            'name' => 'Kaz Productions Ltd',
            'slug' => 'kaz-productions',
            'description' => 'Live music and comedy promoters in Port Louis since 2014.',
            'country_code' => 'MU',
            'default_currency' => 'MUR',
            'payout_currency' => 'MUR',
            'support_email' => 'hello@kazproductions.test',
        ]);

        $this->member($kaz, $manager, OrganizerRole::Manager, $owner);
        $this->member($kaz, $staff, OrganizerRole::Staff, $owner);

        $nairobi = Organizer::factory()->ownedBy($nairobiOwner)->create([
            'name' => 'Nairobi Live Ltd',
            'slug' => 'nairobi-live',
            'description' => 'Jazz, Afro-fusion and food festivals across Nairobi.',
            'country_code' => 'KE',
            'default_currency' => 'KES',
            'payout_currency' => 'KES',
            'support_email' => 'tickets@nairobilive.test',
        ]);

        // --- Venues -------------------------------------------------------
        $caudan = Venue::factory()->create([
            'organizer_id' => null,
            'name' => 'Caudan Waterfront Amphitheatre',
            'address_line' => 'Le Caudan Waterfront',
            'city' => 'Port Louis',
            'postal_code' => '11307',
            'country_code' => 'MU',
            'timezone' => 'Indian/Mauritius',
            'latitude' => '-20.160900',
            'longitude' => '57.497400',
        ]);

        $plaza = Venue::factory()->create([
            'organizer_id' => null,
            'name' => 'Plaza Theatre',
            'address_line' => 'Royal Road',
            'city' => 'Rose-Hill',
            'country_code' => 'MU',
            'timezone' => 'Indian/Mauritius',
        ]);

        $alliance = Venue::factory()->create([
            'organizer_id' => $nairobi->id,
            'name' => 'Alliance Française',
            'address_line' => 'Loita Street',
            'city' => 'Nairobi',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);

        $music = Category::query()->where('slug', 'music')->firstOrFail();
        $arts = Category::query()->where('slug', 'arts')->firstOrFail();

        // --- Events -------------------------------------------------------
        $segaNights = $this->event($kaz, $caudan, $music, [
            'title' => 'Sega Nights: Live on the Waterfront',
            'slug' => 'sega-nights-live-on-the-waterfront',
            'summary' => 'An open-air evening of live sega, seggae and island grooves.',
            'starts_at' => $this->local('2026-11-14 19:00', 'Indian/Mauritius'),
            'ends_at' => $this->local('2026-11-14 23:30', 'Indian/Mauritius'),
            'sales_ends_at' => $this->local('2026-11-14 17:00', 'Indian/Mauritius'),
            'min_age' => 16,
        ], EventStatus::Published);

        $this->ticketType($segaNights, 'Early Bird', 65000, 300, sold: 300, sort: 0,
            salesEnd: $this->local('2026-10-31 23:59', 'Indian/Mauritius'));
        $this->ticketType($segaNights, 'Regular', 85000, 700, sold: 412, sort: 1);
        $this->ticketType($segaNights, 'VIP', 240000, 120, sold: 102, sort: 2, max: 6,
            description: 'Front-of-stage deck, private bar and a welcome drink.');
        $this->ticketType($segaNights, 'VVIP Table · 4 guests', 1200000, 20, sold: 17, sort: 3, max: 2,
            description: 'Reserved table for four with table service.');

        $comedy = $this->event($kaz, $plaza, $arts, [
            'title' => 'Kreol Comedy Night II',
            'slug' => 'kreol-comedy-night-ii',
            'summary' => 'Stand-up in Kreol, French and English from Mauritius\'s best.',
            'starts_at' => $this->local('2026-11-21 20:00', 'Indian/Mauritius'),
            'ends_at' => $this->local('2026-11-21 22:30', 'Indian/Mauritius'),
        ], EventStatus::Published);

        $this->ticketType($comedy, 'Regular', 50000, 400, sold: 164, sort: 0);

        $this->event($kaz, $caudan, $music, [
            'title' => 'New Year Sega Countdown',
            'slug' => 'new-year-sega-countdown',
            'starts_at' => $this->local('2026-12-31 20:00', 'Indian/Mauritius'),
            'ends_at' => $this->local('2027-01-01 01:00', 'Indian/Mauritius'),
        ], EventStatus::Draft);

        $jazz = $this->event($nairobi, $alliance, $music, [
            'title' => 'Afro-Jazz Sessions',
            'slug' => 'afro-jazz-sessions',
            'summary' => 'A night of Afro-jazz with a rotating house band.',
            'timezone' => 'Africa/Nairobi',
            'currency' => 'KES',
            'starts_at' => $this->local('2026-11-27 19:30', 'Africa/Nairobi'),
            'ends_at' => $this->local('2026-11-27 23:00', 'Africa/Nairobi'),
        ], EventStatus::Published);

        $this->ticketType($jazz, 'Regular', 180000, 250, sold: 96, sort: 0);

        // --- Gate staff ---------------------------------------------------
        $assignment = new EventStaffAssignment;
        $assignment->event_id = $segaNights->id;
        $assignment->user_id = $staff->id;
        $assignment->assigned_by = $owner->id;
        $assignment->save();

        // --- December in Mauritius ----------------------------------------
        // A full month of test events across the island (also runnable on
        // its own: php artisan db:seed --class=DecemberMauritiusSeeder).
        $this->call(DecemberMauritiusSeeder::class);

        // --- Demo sales ---------------------------------------------------
        // Paid orders behind the sold counts above, so dashboards have data.
        $this->call(DemoSalesSeeder::class);
    }

    private function user(string $name, string $email): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email]);
    }

    private function member(Organizer $organizer, User $user, OrganizerRole $role, User $invitedBy): void
    {
        OrganizerMember::factory()->create([
            'organizer_id' => $organizer->id,
            'user_id' => $user->id,
            'role' => $role,
            'invited_by' => $invitedBy->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(Organizer $organizer, Venue $venue, Category $category, array $attributes, EventStatus $status): Event
    {
        $published = $status === EventStatus::Published;

        return Event::factory()->forOrganizer($organizer)->create(array_merge([
            'venue_id' => $venue->id,
            'category_id' => $category->id,
            'timezone' => $venue->timezone,
            'status' => $status,
            'submitted_at' => $published ? now()->subDays(36) : null,
            'published_at' => $published ? now()->subDays(35) : null,
            'sales_starts_at' => now()->subDays(35),
            'sales_ends_at' => null,
        ], $attributes));
    }

    private function ticketType(
        Event $event,
        string $name,
        int $amountMinor,
        int $quantity,
        int $sold,
        int $sort,
        int $max = 10,
        ?string $description = null,
        ?CarbonImmutable $salesEnd = null,
    ): void {
        TicketType::factory()->create([
            'event_id' => $event->id,
            'name' => $name,
            'description' => $description,
            'currency' => $event->currency,
            'unit_amount_minor' => $amountMinor,
            'quantity_total' => $quantity,
            'quantity_sold' => $sold,
            'max_per_order' => $max,
            'sort_order' => $sort,
            'sales_ends_at' => $salesEnd,
        ]);
    }

    /**
     * A wall-clock time in the event's zone, converted to UTC for storage.
     */
    private function local(string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($time, $timezone)->utc();
    }
}
