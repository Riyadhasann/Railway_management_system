<?php

use App\Enums\Role;
use App\Enums\RunStatus;
use App\Filament\Pages\ActualTimes;
use App\Models\Booking;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\TrainRunService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00'));
    // 4 stops: dep 08:00 | arr 09:00 dep 09:05 | arr 10:05 dep 10:10 | arr 11:10
    $this->train = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(4)])
        ->withTimetable('08:00')
        ->create();
    $this->run = TrainRun::factory()->for($this->train)->create(['journey_date' => '2026-11-05']);
    $this->stops = $this->train->scheduleStops()->with('routeStop')->get()->sortBy(fn ($s) => $s->routeStop->sequence)->values();
    $this->master = tap(User::factory()->create())->assignRole(Role::StationMaster->value);
    $this->runs = app(TrainRunService::class);
});

function recordTime(int $stop, ?string $arrival, ?string $departure): void
{
    test()->runs->recordActualTime(test()->run, test()->stops[$stop], $arrival, $departure, test()->master);
    test()->run->refresh();
}

it('works out the delay from each stop and moves the run to running, then arrived', function () {
    recordTime(0, null, '08:25');
    expect($this->run->status)->toBe(RunStatus::Running)
        ->and($this->run->delay_minutes)->toBe(25)
        ->and($this->run->progressLabel())->toBe('Running 25 min late');

    recordTime(1, '09:20', '09:22'); // made up some time
    expect($this->run->delay_minutes)->toBe(17);

    recordTime(3, '11:05', null); // early at the terminus
    expect($this->run->status)->toBe(RunStatus::Arrived)
        ->and($this->run->delay_minutes)->toBe(0)
        ->and($this->run->progressLabel())->toBe('Arrived')
        ->and($this->run->stopTimes()->count())->toBe(3);
});

it('rejects times that do not make sense', function (int $stop, ?string $arrival, ?string $departure, string $message) {
    expect(fn () => recordTime($stop, $arrival, $departure))->toThrow(DomainException::class, $message);
})->with([
    'nothing entered' => [1, '', '', 'Enter an arrival or a departure'],
    'arrival at the origin' => [0, '07:55', null, 'no scheduled arrival'],
    'departure from the terminus' => [3, null, '11:30', 'no scheduled departure'],
    'bad format' => [1, '9.20', null, 'HH:MM'],
    'departs before it arrives' => [1, '09:20', '09:10', 'before the arrival'],
    'in the future' => [3, '13:30', null, 'future'],
    'hours early' => [1, '06:30', null, 'more than 2 hours before'],
]);

it('puts a time just after midnight on the next day for a late-night train', function () {
    $night = Train::factory()->state(['train_route_id' => TrainRoute::factory()->withStops(2)])->withTimetable('23:00')->create();
    $run = TrainRun::factory()->for($night)->create(['journey_date' => '2026-11-04']);
    $origin = $night->scheduleStops()->with('routeStop')->get()->sortBy(fn ($s) => $s->routeStop->sequence)->first();

    $time = $this->runs->recordActualTime($run, $origin, null, '00:15', $this->master);

    expect($time->actual_departure->toDateTimeString())->toBe('2026-11-05 00:15:00')
        ->and($time->delay_minutes)->toBe(75);
});

it('refuses a stop from another train and a cancelled run', function () {
    $foreign = Train::factory()->state(['train_route_id' => TrainRoute::factory()->withStops(2)])->withTimetable()->create()->scheduleStops()->first();

    expect(fn () => $this->runs->recordActualTime($this->run, $foreign, null, '08:00', $this->master))->toThrow(InvalidArgumentException::class);

    $this->run->update(['status' => RunStatus::Cancelled]);
    expect(fn () => recordTime(0, null, '08:10'))->toThrow(DomainException::class, 'cancelled');
});

it('lets the station master save times from the page and passengers see the delay', function () {
    $passenger = tap(User::factory()->create())->assignRole(Role::Passenger->value);
    $booking = Booking::factory()->confirmed()->for($this->run)->create(['user_id' => $passenger->id, 'from_seq' => 1, 'to_seq' => 4]);
    $origin = $this->stops[0]->id;

    $this->actingAs($this->master);
    Livewire::test(ActualTimes::class)
        ->set('runId', $this->run->id)
        ->assertSet("times.{$origin}.departure", '')
        ->set("times.{$origin}.departure", '08:25')
        ->call('save', $origin)
        ->assertHasNoErrors()
        ->assertNotified('Saved for '.$this->stops[0]->routeStop->station->name)
        ->set("times.{$origin}.departure", '23:59')
        ->call('save', $origin)
        ->assertHasErrors("times.{$origin}");

    expect($this->run->refresh()->delay_minutes)->toBe(25);

    $this->actingAs($passenger)->get(route('booking.show', $booking))->assertOk()->assertSee('Running 25 min late');
    $this->get(route('booking.mine'))->assertSee('Running 25 min late');
});

it('is open to station masters and admins only', function (Role $role, int $status) {
    $this->actingAs(tap(User::factory()->create())->assignRole($role->value))
        ->get('/admin/actual-times')
        ->assertStatus($status);
})->with([
    'station master' => [Role::StationMaster, 200],
    'admin' => [Role::Admin, 200],
    'tte' => [Role::Tte, 403],
    'planner' => [Role::Planner, 403],
]);
