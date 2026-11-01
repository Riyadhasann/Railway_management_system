<?php

namespace Database\Factories;

use App\Models\RouteStop;
use App\Models\Station;
use App\Models\TrainRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainRoute>
 */
class TrainRouteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('R-###')),
            'name' => fake()->city().' - '.fake()->city(),
            'is_active' => true,
        ];
    }

    /**
     * Add $count new stations, 50 km apart.
     */
    public function withStops(int $count = 3): static
    {
        return $this->afterCreating(function (TrainRoute $route) use ($count) {
            foreach (range(1, $count) as $i) {
                RouteStop::create([
                    'train_route_id' => $route->id,
                    'station_id' => Station::factory()->create()->id,
                    'sequence' => $i,
                    'distance_km' => ($i - 1) * 50,
                ]);
            }
        });
    }
}
