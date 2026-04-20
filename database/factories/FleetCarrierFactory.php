<?php

namespace Database\Factories;

use App\Models\FleetCarrier;
use App\Models\System;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FleetCarrier>
 */
class FleetCarrierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'system_id' => System::factory(),
            'market_id' => $this->faker->unique()->numberBetween(3700000000, 3799999999),
            'name' => strtoupper($this->faker->bothify('???-###')),
            'distance_to_arrival' => $this->faker->numberBetween(0, 1000),
            'allegiance' => 'Independent',
            'government' => 'Fleet Carrier',
            'economy' => 'Fleet Carrier',
            'second_economy' => null,
            'has_market' => $this->faker->boolean(80),
            'has_shipyard' => $this->faker->boolean(30),
            'has_outfitting' => $this->faker->boolean(40),
        ];
    }
}
