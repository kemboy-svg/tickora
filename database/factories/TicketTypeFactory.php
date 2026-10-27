<?php

namespace Database\Factories;

use App\Enums\TicketTypeStatus;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => 'Regular',
            'description' => 'General admission.',
            // Always the event's currency.
            'currency' => fn (array $attributes): string => (string) Event::query()
                ->whereKey($attributes['event_id'])
                ->value('currency'),
            'unit_amount_minor' => 85000,
            'quantity_total' => 500,
            'quantity_sold' => 0,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'sort_order' => 0,
            'status' => TicketTypeStatus::Active,
        ];
    }

    public function free(): static
    {
        return $this->state(fn (): array => ['name' => 'Free', 'unit_amount_minor' => 0]);
    }

    public function vip(): static
    {
        return $this->state(fn (): array => [
            'name' => 'VIP',
            'unit_amount_minor' => 240000,
            'quantity_total' => 60,
            'max_per_order' => 6,
        ]);
    }
}
