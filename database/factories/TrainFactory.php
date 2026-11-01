<?php

namespace Database\Factories;

use App\Models\CoachType;
use App\Models\RakeCoach;
use App\Models\ScheduleStop;
use App\Models\Train;
use App\Models\TrainRoute;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Train>
 */
class TrainFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => (string) fake()->unique()->numberBetween(100, 999),
            'name' => fake()->word().' Express',
            'train_route_id' => TrainRoute::factory()->withStops(),
            'running_days' => [1, 2, 3, 4, 5, 6, 7],
            'is_active' => true,
        ];
    }

    /**
     * @param  list<int>  $days  ISO weekdays
     */
    public function runningOn(array $days): static
    {
        return $this->state(['running_days' => $days]);
    }

    /**
     * Coaches KA, KHA, ... all of one new coach type with seats (12 seats each by default).
     */
    public function withCoaches(int $count = 2): static
    {
        return $this->afterCreating(function (Train $train) use ($count) {
            $type = CoachType::factory()->withSeats()->create();
            $names = ['KA', 'KHA', 'GA', 'GHA', 'CHA', 'JA'];

            foreach (range(0, $count - 1) as $i) {
                RakeCoach::create([
                    'train_id' => $train->id,
                    'coach_no' => $names[$i] ?? 'C'.($i + 1),
                    'coach_type_id' => $type->id,
                    'position' => $i + 1,
                ]);
            }
        });
    }

    /**
     * Timetable over every route stop: departs at $start, 60 min between stops,
     * 5 min dwell. Times past midnight roll into the next day (day_offset).
     */
    public function withTimetable(string $start = '08:00'): static
    {
        return $this->afterCreating(function (Train $train) use ($start) {
            $stops = $train->trainRoute->stops()->get();
            $clock = CarbonImmutable::parse("2000-01-01 {$start}");
            $base = $clock->startOfDay();
            $last = $stops->count() - 1;

            foreach ($stops as $i => $stop) {
                $arrival = $i === 0 ? null : $clock;
                $departure = $i === $last ? null : ($i === 0 ? $clock : $clock->addMinutes(5));
                $reference = $departure ?? $arrival;

                ScheduleStop::create([
                    'train_id' => $train->id,
                    'route_stop_id' => $stop->id,
                    'arrival_time' => $arrival?->format('H:i'),
                    'departure_time' => $departure?->format('H:i'),
                    'day_offset' => (int) $base->diffInDays($reference?->startOfDay() ?? $base),
                ]);

                $clock = ($departure ?? $clock)->addMinutes(60);
            }
        });
    }
}
