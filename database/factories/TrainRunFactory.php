<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Models\Train;
use App\Models\TrainRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainRun>
 */
class TrainRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'train_id' => Train::factory(),
            'journey_date' => now()->addDay()->toDateString(),
            'status' => RunStatus::Scheduled,
            'delay_minutes' => 0,
        ];
    }
}
