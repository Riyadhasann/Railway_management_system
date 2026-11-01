<?php

use App\Enums\RunStatus;
use App\Models\Train;
use App\Models\TrainRun;
use App\Services\TrainRunService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Monday 2 November 2026
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00'));
});

it('creates runs only on the days a train runs', function () {
    $train = Train::factory()->runningOn([1, 3, 5])->create(); // Mon, Wed, Fri

    $created = app(TrainRunService::class)->generate(days: 7);

    expect($created)->toBe(3)
        ->and($train->runs()->orderBy('journey_date')->pluck('journey_date')->map->toDateString()->all())
        ->toBe(['2026-11-02', '2026-11-04', '2026-11-06']);
});

it('creates a run every day for a daily train', function () {
    Train::factory()->create();

    expect(app(TrainRunService::class)->generate(days: 10))->toBe(10);
});

it('is safe to run repeatedly', function () {
    Train::factory()->create();
    $service = app(TrainRunService::class);

    $service->generate(days: 5);

    expect($service->generate(days: 5))->toBe(0)
        ->and(TrainRun::count())->toBe(5);
});

it('never recreates or reopens a cancelled run', function () {
    $train = Train::factory()->create();
    $service = app(TrainRunService::class);
    $service->generate(days: 3);

    $run = $train->runs()->whereDate('journey_date', '2026-11-03')->firstOrFail();
    $service->cancel($run, 'Track maintenance');
    $service->generate(days: 3);

    expect($run->fresh()->status)->toBe(RunStatus::Cancelled)
        ->and(TrainRun::count())->toBe(3);
});

it('skips inactive trains', function () {
    Train::factory()->create(['is_active' => false]);

    expect(app(TrainRunService::class)->generate(days: 5))->toBe(0);
});

it('uses the configured number of days by default', function () {
    config(['railway.runs_ahead_days' => 4]);
    Train::factory()->create();

    expect(app(TrainRunService::class)->generate())->toBe(4);
});

it('works from the artisan command', function () {
    Train::factory()->create();

    $this->artisan('runs:generate', ['--days' => 2, '--from' => '2026-12-01'])
        ->expectsOutput('Created 2 train run(s).')
        ->assertSuccessful();

    expect(TrainRun::pluck('journey_date')->map->toDateString()->sort()->values()->all())
        ->toBe(['2026-12-01', '2026-12-02']);
});

it('is scheduled daily at 01:00', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('runs:generate')
        ->assertSuccessful();
});

it('places overnight stops on the next calendar day', function () {
    $train = Train::factory()->withTimetable('22:30')->create(); // 3 stops: 22:30, 23:30, 00:35 (+1)
    $run = TrainRun::factory()->for($train)->create(['journey_date' => '2026-11-02']);

    $stops = $train->scheduleStops()->get();

    expect($run->scheduledDeparture()?->toDateTimeString())->toBe('2026-11-02 22:30:00')
        ->and($stops[1]->arrivalOn($run->journey_date)?->toDateTimeString())->toBe('2026-11-02 23:30:00')
        ->and($stops[2]->day_offset)->toBe(1)
        ->and($stops[2]->arrivalOn($run->journey_date)?->toDateTimeString())->toBe('2026-11-03 00:35:00');
});

it('only cancels scheduled runs', function () {
    $run = TrainRun::factory()->create(['status' => RunStatus::Arrived]);

    app(TrainRunService::class)->cancel($run, 'Too late');
})->throws(DomainException::class);
