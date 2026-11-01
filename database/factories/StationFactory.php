<?php

namespace Database\Factories;

use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->unique()->city(),
            'division' => fake()->randomElement(['Dhaka', 'Chattogram', 'Rajshahi', 'Khulna', 'Sylhet']),
            'is_active' => true,
        ];
    }
}
