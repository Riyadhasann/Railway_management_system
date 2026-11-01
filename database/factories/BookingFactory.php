<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Models\Booking;
use App\Models\TrainRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare booking rows (no seats). Real bookings are made through SeatInventoryService::hold().
 *
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pnr' => strtoupper(fake()->unique()->bothify('??####')),
            'train_run_id' => TrainRun::factory(),
            'channel' => Channel::Online,
            'from_seq' => 1,
            'to_seq' => 2,
            'total_poisha' => 50000,
            'status' => BookingStatus::PendingPayment,
            'hold_until' => now()->addMinutes(10),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(['status' => BookingStatus::Confirmed, 'hold_until' => null, 'confirmed_at' => now()]);
    }
}
