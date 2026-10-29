<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Money;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local demo data only: paid orders whose ticket counts add up exactly to
 * each seeded ticket type's quantity_sold, spread over the weeks since the
 * event went on sale (busier towards today). Gives the dashboards realistic
 * figures without a payment provider. Deterministic, so every seed matches.
 */
class DemoSalesSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        mt_srand(2026);

        $customers = collect(range(1, 40))->map(fn (int $i): User => User::factory()->create([
            'name' => fake()->name(),
            'email' => sprintf('customer%02d@example.test', $i),
        ]));

        $feeBps = (int) config('tickora.platform_fee_bps', 500);
        $now = CarbonImmutable::now();

        $events = Event::query()
            ->where('status', EventStatus::Published)
            ->with('ticketTypes')
            ->get();

        foreach ($events as $event) {
            $since = CarbonImmutable::instance($event->published_at ?? $now->subDays(30));
            $window = max(1, (int) $since->diffInMinutes($now));

            foreach ($event->ticketTypes as $type) {
                $this->sell($event, $type, $type->quantity_sold, array_values($customers->all()), $since, $window, $feeBps);
            }
        }

        $this->giveOrdersTo('amara@example.test', $events->pluck('id')->all());
    }

    /**
     * Hand one recent order per event to the demo customer, so signing in
     * as them shows tickets on the dashboard.
     *
     * @param  list<int>  $eventIds
     */
    private function giveOrdersTo(string $email, array $eventIds): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        foreach ($eventIds as $eventId) {
            $orderId = DB::table('orders')->where('event_id', $eventId)->orderByDesc('paid_at')->value('id');

            if ($orderId !== null) {
                DB::table('orders')->where('id', $orderId)->update([
                    'user_id' => $user->id,
                    'customer_email' => $user->email,
                    'customer_name' => $user->name,
                ]);
            }
        }
    }

    /**
     * @param  list<User>  $customers
     */
    private function sell(Event $event, TicketType $type, int $toSell, array $customers, CarbonImmutable $since, int $window, int $feeBps): void
    {
        $orders = [];
        $items = [];

        while ($toSell > 0) {
            $quantity = min($toSell, mt_rand(1, min(4, $type->max_per_order)));
            $toSell -= $quantity;

            // Skew towards recent days: more people buy as the event nears.
            $paidAt = $since->addMinutes((int) round($window * (mt_rand() / mt_getrandmax()) ** 0.6));
            $customer = $customers[mt_rand(0, count($customers) - 1)];

            $subtotal = $type->unit_amount_minor * $quantity;
            $fee = Money::ofMinor($subtotal, $event->currency)->percentageBps($feeBps)->amount;
            $reference = Reference::order();

            $orders[$reference] = [
                'public_id' => Str::lower((string) Str::ulid()),
                'reference' => $reference,
                'user_id' => $customer->id,
                'customer_email' => $customer->email,
                'customer_name' => $customer->name,
                'organizer_id' => $event->organizer_id,
                'event_id' => $event->id,
                'currency' => $event->currency,
                'subtotal_minor' => $subtotal,
                'discount_minor' => 0,
                'platform_fee_minor' => $fee,
                'tax_minor' => 0,
                'total_minor' => $subtotal + $fee,
                'refunded_minor' => 0,
                'status' => OrderStatus::Paid->value,
                'paid_at' => $paidAt,
                'created_at' => $paidAt,
                'updated_at' => $paidAt,
            ];

            $items[$reference] = [
                'ticket_type_id' => $type->id,
                'ticket_name_snapshot' => $type->name,
                'quantity' => $quantity,
                'unit_amount_minor' => $type->unit_amount_minor,
                'discount_minor' => 0,
                'line_total_minor' => $subtotal,
                'fee_minor' => $fee,
                'tax_minor' => 0,
                'created_at' => $paidAt,
                'updated_at' => $paidAt,
            ];
        }

        foreach (array_chunk($orders, 200, true) as $chunk) {
            DB::table('orders')->insert(array_values($chunk));

            $ids = DB::table('orders')->whereIn('reference', array_keys($chunk))->pluck('id', 'reference');

            DB::table('order_items')->insert(array_map(
                fn (string $reference): array => ['order_id' => $ids[$reference]] + $items[$reference],
                array_keys($chunk),
            ));
        }
    }
}
