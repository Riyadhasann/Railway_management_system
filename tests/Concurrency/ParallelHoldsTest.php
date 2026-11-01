<?php

use App\Enums\BookingStatus;
use App\Enums\SeatClass;
use App\Models\Booking;
use App\Models\FareRule;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
 * Real parallel requests: booking:race starts separate PHP processes, so the data
 * must be committed (no RefreshDatabase transaction) and the database must have
 * row locks. Runs in CI on MySQL; skipped on SQLite. Kept in its own suite, after Feature.
 */
uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function () {
    if (! in_array(config('database.default'), ['mysql', 'mariadb', 'pgsql'], true)) {
        $this->markTestSkipped('Needs MySQL or PostgreSQL row locks.');
    }
});

it('lets exactly one of 20 parallel holds on the same seat succeed', function () {
    $train = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(3)])
        ->withTimetable()
        ->withCoaches(1)
        ->create();
    $run = TrainRun::factory()->for($train)->create();
    FareRule::create(['seat_class' => SeatClass::ShovanChair, 'km_from' => 0, 'km_to' => 1000, 'fare_poisha' => 10000]);

    $exitCode = Artisan::call('booking:race', ['--run' => $run->id, '--attempts' => 20, '--warmup' => 5, '--keep' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0, $output)
        ->and($output)->toContain('Succeeded: 1, seat taken: 19, errors: 0')
        ->and(Booking::count())->toBe(1)
        ->and(Booking::sole()->status)->toBe(BookingStatus::PendingPayment)
        ->and(Booking::sole()->seatBookings()->count())->toBe(1);
});
