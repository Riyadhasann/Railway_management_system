<?php

namespace Database\Factories;

use App\Enums\StaffDesignation;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_no' => 'BR'.fake()->unique()->numerify('#####'),
            'name' => fake()->name(),
            'designation' => StaffDesignation::Guard,
            'phone' => '017'.fake()->numerify('########'),
            'is_active' => true,
        ];
    }

    /**
     * A driver whose licence is valid for another year.
     */
    public function driver(): static
    {
        return $this->state([
            'designation' => StaffDesignation::Driver,
            'licence_no' => 'DL-'.fake()->unique()->numerify('######'),
            'licence_expires_on' => now()->addYear()->toDateString(),
        ]);
    }

    public function licenceExpired(): static
    {
        return $this->state(['licence_expires_on' => now()->subDays(10)->toDateString()]);
    }
}
