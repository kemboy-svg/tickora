<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Enums\MembershipStatus;
use App\Enums\OrganizerRole;
use App\Enums\TicketTypeStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\Organizer;
use App\Models\OrganizerMember;
use App\Models\PromoCode;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Venue;
use App\Services\Media\ImageProcessor;
use Carbon\CarbonImmutable;
use GdImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A December 2026 programme across Mauritius for local testing, from
 * fictional organisers. Every case worth clicking through is covered:
 *
 *  - free events, paid events, several ticket tiers, group/table tickets
 *  - sold out (whole event and single tiers), nearly sold out
 *  - ticket sales that have not opened yet
 *  - an age-restricted New Year's Eve party running past midnight
 *  - promo codes (percentage and fixed), an unlisted event, and one event
 *    waiting in the admin review queue
 *
 * Generated cover photos are added when GD is available. Sold counts are
 * backed by demo paid orders (DemoSalesSeeder). Safe to run again: events
 * that already exist are skipped.
 *
 *   php artisan db:seed --class=DecemberMauritiusSeeder
 */
class DecemberMauritiusSeeder extends Seeder
{
    private const TZ = 'Indian/Mauritius';

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $this->call(CategorySeeder::class);

        $islandBeats = $this->organizer('Island Beats Collective', 'island-beats', 'Sega, seggae and beach festivals all around the island.', 'Zara Ramphul', 'zara@islandbeats.test');
        $lagoon = $this->organizer('Lagoon Live Events', 'lagoon-live', 'Markets, food nights and family events in Port Louis and the south.', 'Dev Appadoo', 'dev@lagoonlive.test');
        $morisTech = $this->organizer('Moris Tech Community', 'moris-tech', 'Meetups, summits and pitch nights for the Mauritian tech scene.', 'Yash Gopee', 'yash@moristech.test');
        $runMoris = $this->organizer('Run Moris Club', 'run-moris', 'Road races and fun runs for every pace.', 'Lea Baptiste', 'lea@runmoris.test');
        $stagelight = $this->organizer('Stagelight Theatre Company', 'stagelight-theatre', 'Comedy and family shows on stages across the island.', 'Rohan Seebaluck', 'rohan@stagelight.test');

        $flicEnFlac = $this->venue('Flic en Flac Public Beach', 'Coastal Road', 'Flic en Flac', 'Black River', '-20.2740', '57.3710');
        $jardin = $this->venue('Jardin de la Compagnie', 'Rue Poudrière', 'Port Louis', 'Port Louis', '-20.1625', '57.5005');
        $ebene = $this->venue('Ebène Conference Hall', 'Cybercity', 'Ebène', 'Plaines Wilhems', '-20.2440', '57.4870');
        $plaza = $this->venue('Plaza Theatre', 'Royal Road', 'Rose-Hill', 'Plaines Wilhems', '-20.2400', '57.4690');
        $grandBaie = $this->venue('Grand Baie Beachfront Stage', 'Royal Road', 'Grand Baie', 'Rivière du Rempart', '-20.0130', '57.5800');
        $mahebourg = $this->venue('Mahébourg Waterfront', 'Rue des Hollandais', 'Mahébourg', 'Grand Port', '-20.4070', '57.7060');
        $caudan = $this->venue('Caudan Waterfront Amphitheatre', 'Le Caudan Waterfront', 'Port Louis', 'Port Louis', '-20.1609', '57.4974');
        $curepipe = $this->venue('Curepipe Botanical Garden', 'Rue Botanique', 'Curepipe', 'Plaines Wilhems', '-20.3200', '57.5210');
        $leMorne = $this->venue('Le Morne Public Beach', 'Coastal Road', 'Le Morne', 'Black River', '-20.4560', '57.3150');
        $trouAuxBiches = $this->venue('Trou aux Biches Beach', 'Coastal Road', 'Trou aux Biches', 'Pamplemousses', '-20.0360', '57.5450');

        // --- Music & festivals -------------------------------------------

        $this->event($islandBeats, $flicEnFlac, 'festivals', [
            'title' => 'Sega & Seggae Beach Festival',
            'summary' => 'Ten live bands, food stalls and a sunset ravanne circle on Flic en Flac beach.',
            'starts' => '2026-12-04 16:00', 'ends' => '2026-12-04 23:00',
            'palette' => [[255, 107, 107], [109, 74, 255]],
        ], [
            ['Early Bird', 45000, 300, 300, 'Sold out: the first 300 tickets.'],
            ['General Admission', 65000, 1200, 640, null],
            ['VIP Lounge', 180000, 150, 92, 'Shaded deck, private bar and a welcome cocktail.', 'max' => 6],
        ]);

        $this->event($islandBeats, $grandBaie, 'music', [
            'title' => 'Jazz on the Bay',
            'summary' => 'A Sunday evening of Afro-jazz and island soul with the bay as the backdrop.',
            'starts' => '2026-12-13 18:30', 'ends' => '2026-12-13 22:00',
            'palette' => [[20, 30, 80], [0, 160, 190]],
        ], [
            ['Standing', 90000, 250, 120, null],
            ['Table for 4', 400000, 25, 23, 'Reserved beachfront table for four, with a bottle to share.', 'max' => 2],
        ]);

        $this->event($islandBeats, $leMorne, 'music', [
            'title' => 'Sunset Sessions Le Morne',
            'summary' => 'Deep house and live percussion under Le Morne Brabant. Tickets go on sale on 1 December.',
            'starts' => '2026-12-26 15:00', 'ends' => '2026-12-26 21:00',
            'sales_starts' => '2026-12-01 10:00',
            'palette' => [[255, 150, 60], [140, 40, 120]],
        ], [
            ['General Admission', 75000, 600, 0, null],
            ['VIP Deck', 200000, 100, 0, 'Raised deck with the best view of the sunset.', 'max' => 4],
        ]);

        $this->event($islandBeats, $trouAuxBiches, 'festivals', [
            'title' => "New Year's Eve Beach Party 2027",
            'summary' => 'Count down to 2027 with DJs, fire dancers and fireworks over the lagoon. Strictly 18+.',
            'starts' => '2026-12-31 20:00', 'ends' => '2027-01-01 02:00',
            'min_age' => 18,
            'palette' => [[10, 10, 40], [255, 200, 60]],
        ], [
            ['Early Bird', 120000, 200, 200, 'Sold out.'],
            ['General Admission', 160000, 800, 350, null],
            ['VIP Cabana', 450000, 80, 71, 'Private cabana, table service and a midnight champagne toast.', 'max' => 4],
        ]);

        // --- Community, food & family ------------------------------------

        $this->event($lagoon, $jardin, 'community', [
            'title' => 'Port Louis Christmas Market',
            'summary' => 'Local crafts, Christmas treats and live carols in the heart of the capital. Free entry.',
            'starts' => '2026-12-05 10:00', 'ends' => '2026-12-05 18:00',
            'palette' => [[200, 30, 50], [30, 120, 70]],
        ], [
            ['Free Entry', 0, 2000, 380, 'Free, but please reserve so we can plan for numbers.', 'max' => 6],
            ['Wreath-Making Workshop', 25000, 40, 31, 'Includes all materials; take your wreath home.', 'max' => 2],
        ]);

        $this->event($lagoon, $caudan, 'food-drink', [
            'title' => 'Street Food Night Market',
            'summary' => 'Dholl puri, mine frit, gateaux piments and fifty more stalls along the waterfront.',
            'starts' => '2026-12-18 17:00', 'ends' => '2026-12-18 23:00',
            'palette' => [[255, 170, 40], [200, 60, 30]],
        ], [
            ['Entry', 20000, 1500, 420, null],
            ['Tasting Pass', 95000, 200, 61, 'Entry plus ten tasting tokens.', 'max' => 6],
        ]);

        $this->event($lagoon, $curepipe, 'community', [
            'title' => 'Carols by Candlelight',
            'summary' => 'Choirs from across the island sing by candlelight in the Botanical Garden.',
            'starts' => '2026-12-20 18:00', 'ends' => '2026-12-20 20:30',
            'palette' => [[30, 40, 90], [240, 200, 120]],
        ], [
            ['Free Seat', 0, 800, 210, 'Bring a blanket; candles provided.', 'max' => 8],
        ]);

        $this->event($stagelight, $plaza, 'arts', [
            'title' => 'Kids Christmas Magic Show',
            'summary' => 'Magic, puppets and a visit from Papa Noël. Perfect for ages 3 to 10.',
            'starts' => '2026-12-23 14:00', 'ends' => '2026-12-23 16:00',
            'palette' => [[255, 120, 180], [90, 60, 220]],
        ], [
            ['Child', 30000, 250, 88, null],
            ['Adult', 40000, 200, 70, null],
            ['Family Pack (2 adults + 2 children)', 120000, 60, 24, 'Four seats together.', 'max' => 2],
        ]);

        $this->event($stagelight, $plaza, 'arts', [
            'title' => 'Kreol Comedy Christmas Special',
            'summary' => 'The year in review, island style. Stand-up in Kreol, French and English.',
            'starts' => '2026-12-12 20:00', 'ends' => '2026-12-12 22:30',
            'min_age' => 16,
            'palette' => [[60, 20, 90], [255, 90, 90]],
        ], [
            ['Regular', 60000, 400, 400, 'Sold out.'],
        ]);

        // --- Tech & business --------------------------------------------

        $this->event($morisTech, $ebene, 'technology', [
            'title' => 'Mauritius AI & Developer Summit',
            'summary' => 'Talks and hands-on workshops on AI, cloud and building products from Mauritius.',
            'starts' => '2026-12-10 09:00', 'ends' => '2026-12-10 17:30',
            'palette' => [[20, 20, 60], [109, 74, 255]],
        ], [
            ['Standard', 150000, 300, 188, null],
            ['Student', 50000, 80, 80, 'Sold out. Bring your student card.'],
            ['Workshop Add-on', 75000, 40, 37, 'Afternoon hands-on lab; only a few places left.', 'max' => 1],
        ]);

        $this->event($morisTech, $ebene, 'business', [
            'title' => 'Startup Pitch Night',
            'summary' => 'Eight early-stage founders pitch to investors. Networking drinks afterwards.',
            'starts' => '2026-12-15 18:00', 'ends' => '2026-12-15 21:00',
            'palette' => [[15, 60, 80], [60, 200, 160]],
        ], [
            ['Regular', 30000, 150, 44, null],
        ]);

        $this->event($morisTech, $ebene, 'business', [
            'title' => 'Year-End Partners Gala',
            'summary' => 'Invitation-only dinner for community partners. Unlisted: reachable by direct link only.',
            'starts' => '2026-12-17 19:00', 'ends' => '2026-12-17 23:00',
            'visibility' => EventVisibility::Unlisted,
            'palette' => [[40, 30, 20], [210, 170, 90]],
        ], [
            ['Dinner Seat', 250000, 120, 35, null, 'max' => 2],
        ]);

        // --- Sports -------------------------------------------------------

        $this->event($runMoris, $mahebourg, 'sports', [
            'title' => 'Lagoon Run 10K',
            'summary' => 'A flat, fast course along the Mahébourg waterfront at sunrise. Medals for every finisher.',
            'starts' => '2026-12-13 06:00', 'ends' => '2026-12-13 10:00',
            'palette' => [[0, 120, 200], [120, 220, 200]],
        ], [
            ['10K', 80000, 500, 210, 'Chip-timed.', 'max' => 4],
            ['5K Fun Run', 40000, 400, 155, null, 'max' => 6],
            ['Kids 1K', 15000, 150, 40, 'Ages 5 to 12.', 'max' => 4],
        ]);

        // Waiting for the admin's review, to test the moderation queue.
        $this->event($runMoris, $mahebourg, 'sports', [
            'title' => 'Boxing Day Regatta',
            'summary' => 'Pirogue races across the bay, with music and food on the shore.',
            'starts' => '2026-12-26 09:00', 'ends' => '2026-12-26 15:00',
            'status' => EventStatus::PendingReview,
            'palette' => [[0, 90, 140], [255, 220, 120]],
        ], [
            ['Spectator', 10000, 1000, 0, null],
        ]);

        // --- Promo codes --------------------------------------------------

        $this->promo($islandBeats, 'XMAS10', 'percent', 1000, null, '2026-12-24 23:59', null);
        $this->promo($runMoris, 'EARLYRUN', 'fixed', 10000, 'MUR', null, 50);

        // Paid orders behind the sold counts above, so dashboards have data.
        $this->call(DemoSalesSeeder::class);
    }

    private function organizer(string $name, string $slug, string $description, string $ownerName, string $ownerEmail): Organizer
    {
        $existing = Organizer::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            return $existing;
        }

        $owner = User::query()->where('email', $ownerEmail)->first()
            ?? User::factory()->create(['name' => $ownerName, 'email' => $ownerEmail]);

        $organizer = Organizer::factory()->ownedBy($owner)->create([
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'country_code' => 'MU',
            'default_currency' => 'MUR',
            'payout_currency' => 'MUR',
            'support_email' => 'hello@'.explode('@', $ownerEmail)[1],
        ]);

        // The factory adds the owner membership; make sure it is active.
        OrganizerMember::query()
            ->where('organizer_id', $organizer->id)
            ->where('user_id', $owner->id)
            ->update(['role' => OrganizerRole::Owner->value, 'status' => MembershipStatus::Active->value]);

        return $organizer;
    }

    private function venue(string $name, string $address, string $city, string $region, string $lat, string $lng): Venue
    {
        return Venue::query()->whereNull('organizer_id')->where('name', $name)->first()
            ?? Venue::factory()->create([
                'organizer_id' => null,
                'name' => $name,
                'address_line' => $address,
                'city' => $city,
                'region' => $region,
                'country_code' => 'MU',
                'timezone' => self::TZ,
                'latitude' => $lat,
                'longitude' => $lng,
            ]);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<array<int|string, mixed>>  $tiers  [name, price minor, capacity, sold, description, 'max' => n]
     */
    private function event(Organizer $organizer, Venue $venue, string $category, array $details, array $tiers): void
    {
        $title = (string) $details['title'];
        $slug = Str::slug($title).'-dec-2026';

        if (Event::query()->where('slug', $slug)->exists()) {
            return;
        }

        $status = $details['status'] ?? EventStatus::Published;
        $published = $status === EventStatus::Published;
        $starts = $this->local((string) $details['starts']);
        $ends = $this->local((string) $details['ends']);

        $event = Event::factory()->forOrganizer($organizer)->create([
            'venue_id' => $venue->id,
            'category_id' => Category::query()->where('slug', $category)->value('id'),
            'title' => $title,
            'slug' => $slug,
            'summary' => (string) $details['summary'],
            'description' => ((string) $details['summary'])."\n\nDoors open 30 minutes before the start. Tickets are checked at the entrance; have your QR code ready on your phone.",
            'timezone' => self::TZ,
            'currency' => 'MUR',
            'starts_at' => $starts,
            'ends_at' => $ends,
            'sales_starts_at' => isset($details['sales_starts']) ? $this->local((string) $details['sales_starts']) : now()->subDays(30),
            'sales_ends_at' => $starts->subHour(),
            'status' => $status,
            'visibility' => $details['visibility'] ?? EventVisibility::Public,
            'min_age' => $details['min_age'] ?? null,
            'submitted_at' => now()->subDays(32),
            'published_at' => $published ? now()->subDays(30) : null,
            'refund_policy' => 'Refunds up to 7 days before the event, excluding the booking fee.',
        ]);

        foreach ($tiers as $sort => $tier) {
            TicketType::factory()->create([
                'event_id' => $event->id,
                'name' => $tier[0],
                'unit_amount_minor' => $tier[1],
                'quantity_total' => $tier[2],
                'quantity_sold' => $tier[3],
                'description' => $tier[4] ?? null,
                'max_per_order' => $tier['max'] ?? 10,
                'sort_order' => $sort,
                'status' => TicketTypeStatus::Active,
            ]);
        }

        /** @var array{0: array{int, int, int}, 1: array{int, int, int}} $palette */
        $palette = $details['palette'];
        $this->cover($event, $palette);
    }

    private function promo(Organizer $organizer, string $code, string $type, int $value, ?string $currency, ?string $ends, ?int $max): void
    {
        if (PromoCode::query()->where('organizer_id', $organizer->id)->where('code', $code)->exists()) {
            return;
        }

        $promo = new PromoCode([
            'code' => $code,
            'discount_type' => $type,
            'discount_value' => $value,
            'currency' => $currency,
            'ends_at' => $ends !== null ? $this->local($ends) : null,
            'max_redemptions' => $max,
            'max_per_customer' => 2,
            'is_active' => true,
        ]);
        $promo->organizer_id = $organizer->id;
        $promo->save();
    }

    /**
     * A generated "stage lights" cover: a diagonal gradient between two
     * colours with soft glowing circles. No text, no real photos.
     *
     * @param  array{0: array{int, int, int}, 1: array{int, int, int}}  $palette
     */
    private function cover(Event $event, array $palette): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            return;
        }

        $width = 1600;
        $height = 1000;
        $image = imagecreatetruecolor($width, $height);

        if (! $image instanceof GdImage) {
            return;
        }

        [$from, $to] = $palette;

        for ($y = 0; $y < $height; $y += 4) {
            $t = $y / $height;
            $color = imagecolorallocate(
                $image,
                self::channel($from[0] + ($to[0] - $from[0]) * $t),
                self::channel($from[1] + ($to[1] - $from[1]) * $t),
                self::channel($from[2] + ($to[2] - $from[2]) * $t),
            );
            imagefilledrectangle($image, 0, $y, $width, $y + 4, (int) $color);
        }

        imagealphablending($image, true);
        mt_srand(crc32($event->slug));

        for ($i = 0; $i < 14; $i++) {
            $size = mt_rand(80, 420);
            $glow = imagecolorallocatealpha($image, 255, 255, 255, mt_rand(96, 120));
            imagefilledellipse($image, mt_rand(0, $width), mt_rand(0, $height), $size, $size, (int) $glow);
        }

        $path = tempnam(sys_get_temp_dir(), 'cover');

        if ($path === false) {
            return;
        }

        imagejpeg($image, $path, 88);

        $processed = app(ImageProcessor::class)->process($path);
        @unlink($path);

        $disk = config('tickora.media_disk', 'public');
        $disk = is_string($disk) ? $disk : 'public';
        $stored = 'events/'.$event->public_id.'/'.Str::lower((string) Str::ulid()).'.'.$processed->extension;

        Storage::disk($disk)->put($stored, $processed->contents, ['visibility' => 'public']);

        $model = new EventImage;
        $model->event_id = $event->id;
        $model->disk = $disk;
        $model->path = $stored;
        $model->mime_type = $processed->mimeType;
        $model->width = $processed->width;
        $model->height = $processed->height;
        $model->size_bytes = $processed->size();
        $model->sort_order = 0;
        $model->save();

        $event->cover_image_key = $stored;
        $event->save();
    }

    /**
     * @return int<0, 255>
     */
    private static function channel(float|int $value): int
    {
        return max(0, min(255, (int) $value));
    }

    private function local(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, self::TZ)->utc();
    }
}
