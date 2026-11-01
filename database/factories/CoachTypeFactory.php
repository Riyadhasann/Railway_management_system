<?php

namespace Database\Factories;

use App\Enums\SeatClass;
use App\Models\CoachType;
use App\Services\SeatLayoutService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoachType>
 */
class CoachTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('CT_###')),
            'name' => fake()->word().' coach',
            'seat_class' => SeatClass::ShovanChair,
            'layout_rows' => 3,
            'layout_cols' => 5,
            'is_active' => true,
        ];
    }

    /**
     * Fill the grid with seats, leaving the given aisle columns empty
     * (default 3 x 5 grid with aisle 3 = 12 seats numbered 1..12).
     *
     * @param  list<int>  $aisles
     */
    public function withSeats(array $aisles = [3]): static
    {
        return $this->afterCreating(function (CoachType $type) use ($aisles) {
            $service = app(SeatLayoutService::class);
            $service->save($type, $service->fill(
                $type->layout_rows, $type->layout_cols, $aisles, $type->seat_class->hasBerths(),
            ));
        });
    }
}
