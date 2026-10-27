<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Support\Reference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A paid order whose organiser and currency always match its event.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory()->published(),
            'organizer_id' => fn (array $attributes): int => (int) Event::query()
                ->whereKey($attributes['event_id'])
                ->value('organizer_id'),
            'currency' => fn (array $attributes): string => (string) Event::query()
                ->whereKey($attributes['event_id'])
                ->value('currency'),
            'user_id' => User::factory(),
            'reference' => Reference::order(),
            'customer_email' => fake()->safeEmail(),
            'customer_name' => fake()->name(),
            'subtotal_minor' => 170000,
            'discount_minor' => 0,
            'platform_fee_minor' => 8500,
            'tax_minor' => 0,
            'total_minor' => 178500,
            'refunded_minor' => 0,
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ];
    }

    public function pendingPayment(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::PendingPayment,
            'paid_at' => null,
        ]);
    }
}
