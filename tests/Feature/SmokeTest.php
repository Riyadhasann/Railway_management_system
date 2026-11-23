<?php

use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Filament\Pages\ActualTimes;
use App\Filament\Pages\CounterBooking;
use App\Filament\Pages\CrewAssignments;
use App\Filament\Pages\PassengerChart;
use App\Filament\Pages\TicketScanner;
use App\Models\Booking;
use App\Models\CoachType;
use App\Models\Station;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\SeatInventoryService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoCoachesSeeder;
use Database\Seeders\DemoFaresSeeder;
use Database\Seeders\DemoNetworkSeeder;
use Database\Seeders\DemoRefundRulesSeeder;
use Database\Seeders\DemoStaffSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\PastBookingsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Every screen opens with a realistic amount of demo data (a day of history, thousands of
 * seats), without N+1 queries: lazy loading in a list throws in tests (AppServiceProvider),
 * and each page has a query budget so a query per row is caught too.
 */

const SMOKE_QUERY_BUDGET = 80;

beforeEach(function () {
    Storage::fake('local');
    config(['railway.demo_password' => 'demo-pass-123']);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00'));

    $this->seed([
        RolesAndPermissionsSeeder::class, DemoUsersSeeder::class, DemoNetworkSeeder::class, DemoCoachesSeeder::class,
        DemoFaresSeeder::class, DemoRefundRulesSeeder::class, DemoStaffSeeder::class,
    ]);
    $seeder = app(PastBookingsSeeder::class);
    $seeder->days = 1;
    $seeder->run();

    $this->admin = User::where('email', 'admin@railway-erp.test')->firstOrFail();

    // The busiest run that leaves today: the one with the most bookings.
    $this->run = TrainRun::query()->whereDate('journey_date', '2026-11-05')->withCount('bookings')->orderByDesc('bookings_count')->firstOrFail();
    $this->booking = Booking::where('channel', Channel::Online)->where('status', BookingStatus::Confirmed)->whereHas('trainRun', fn ($q) => $q->whereDate('journey_date', '>=', '2026-11-05'))->firstOrFail();
});

/**
 * Run $fn and fail if it runs more queries than the budget.
 */
function withinQueryBudget(string $page, Closure $fn): void
{
    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    $fn();

    expect($count)->toBeLessThanOrEqual(SMOKE_QUERY_BUDGET, "{$page} ran {$count} queries");
}

it('opens every admin page', function (string $uri) {
    $this->actingAs($this->admin);

    withinQueryBudget($uri, fn () => $this->get($uri)->assertOk());
})->with([
    '/admin', '/admin/audit-log', '/admin/coach-types', '/admin/concessions', '/admin/counter-booking',
    '/admin/counter-cancellation', '/admin/crew-assignments', '/admin/demo-data', '/admin/fare-rules',
    '/admin/locomotives', '/admin/passenger-chart', '/admin/payments', '/admin/refund-rules', '/admin/refunds',
    '/admin/reports', '/admin/staff', '/admin/stations', '/admin/ticket-scanner', '/admin/train-routes',
    '/admin/train-runs', '/admin/trains', '/admin/actual-times',
]);

it('opens the edit and seat map pages', function () {
    $this->actingAs($this->admin);

    foreach ([
        "/admin/train-runs/{$this->run->id}/seats",
        '/admin/trains/'.Train::first()->id.'/edit',
        '/admin/train-routes/'.TrainRoute::first()->id.'/edit',
        '/admin/stations/'.Station::first()->id.'/edit',
        '/admin/coach-types/'.CoachType::first()->id.'/edit',
        '/admin/coach-types/'.CoachType::first()->id.'/layout',
    ] as $uri) {
        withinQueryBudget($uri, fn () => $this->get($uri)->assertOk());
    }
});

it('shows a full run on the staff pages', function (string $page) {
    $this->actingAs($this->admin);

    withinQueryBudget($page, fn () => Livewire::test($page)->set('runId', $this->run->id)->assertOk());
})->with([
    'passenger chart' => PassengerChart::class,
    'ticket scanner' => TicketScanner::class,
    'actual times' => ActualTimes::class,
    'crew assignments' => CrewAssignments::class,
    'counter booking' => CounterBooking::class,
]);

it('opens every passenger page', function () {
    $this->actingAs($this->booking->user);
    $tomorrow = TrainRun::where('train_id', $this->run->train_id)->whereDate('journey_date', '2026-11-06')->firstOrFail(); // today's may have left
    [$from, $to] = app(SeatInventoryService::class)->fullJourney($tomorrow);
    $route = $this->run->train->scheduleStops()->with('routeStop')->get();
    $origin = $route->first()->routeStop->station_id;
    $destination = $route->last()->routeStop->station_id;

    foreach ([
        '/book/bookings',
        "/book/bookings/{$this->booking->pnr}",
        "/tickets/{$this->booking->pnr}/pdf",
        "/book?from={$origin}&to={$destination}&date=2026-11-06",
        "/book/runs/{$tomorrow->id}/seats/{$from}/{$to}",
    ] as $uri) {
        withinQueryBudget($uri, fn () => expect($this->get($uri)->status())->toBe(200, $uri));
    }
});
