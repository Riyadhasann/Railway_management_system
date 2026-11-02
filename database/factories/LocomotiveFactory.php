<?php

namespace Database\Factories;

use App\Enums\LocoStatus;
use App\Models\Locomotive;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Locomotive>
 */
class LocomotiveFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loco_no' => (string) fake()->unique()->numberBetween(2000, 3999),
            'model' => fake()->randomElement(['MEI 3000 class', 'Hyundai Rotem 2900 class', 'Bombardier 2600 class']),
            'traction' => 'diesel',
            'home_shed' => fake()->randomElement(['Dhaka', 'Pahartali', 'Parbatipur']),
            'status' => LocoStatus::Available,
            'last_maintenance_on' => now()->subMonths(2)->toDateString(),
            'next_maintenance_due' => now()->addMonths(2)->toDateString(),
        ];
    }

    public function underRepair(): static
    {
        return $this->state(['status' => LocoStatus::UnderRepair]);
    }
}
