<?php

namespace Database\Factories;

use App\Models\Organizer;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organizer_id' => Organizer::factory(),
            'name' => fake()->company().' Hall',
            'address_line' => fake()->streetAddress(),
            'city' => 'Port Louis',
            'region' => null,
            'postal_code' => null,
            'country_code' => 'MU',
            'timezone' => 'Indian/Mauritius',
            'latitude' => null,
            'longitude' => null,
        ];
    }

    public function shared(): static
    {
        return $this->state(fn (): array => ['organizer_id' => null]);
    }
}
