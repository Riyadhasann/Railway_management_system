<?php

use App\Enums\Channel;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Enums\SeatClass;
use App\Livewire\Booking\SearchTrains;
use App\Models\FareRule;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\SeatInventoryService;
use App\Services\TrainSearchService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    // 4 stops 50 km apart; 2 Shovan chair coaches of 12 seats.
    $this->train = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(4)])
        ->withTimetable('09:00')
        ->withCoaches(2)
        ->create();
    $this->stations = $this->train->trainRoute->stops()->with('station')->get()->pluck('station');
    $this->tomorrow = CarbonImmutable::tomorrow();
    $this->run = TrainRun::factory()->for($this->train)->create(['journey_date' => $this->tomorrow->toDateString()]);
    FareRule::create(['seat_class' => SeatClass::ShovanChair, 'km_from' => 0, 'km_to' => 1000, 'fare_poisha' => 12000]);
    $this->search = app(TrainSearchService::class);
});

it('finds the train between two of its stops with times, distance, free seats and fare', function () {
    $results = $this->search->search($this->stations[1], $this->stations[3], $this->tomorrow);

    expect($results)->toHaveCount(1);
    $option = $results[0];
    expect($option->run->is($this->run))->toBeTrue()
        ->and([$option->fromSeq, $option->toSeq])->toBe([2, 4])
        ->and($option->departure->format('H:i'))->toBe('10:05')
        ->and($option->distanceKm)->toBe(100)
        ->and($option->classes)->toBe([['class' => SeatClass::ShovanChair, 'available' => 24, 'fare' => 12000]])
        ->and($option->isBookable())->toBeTrue();
});

it('does not offer the train in the opposite direction, on other dates, or between the same station', function () {
    expect($this->search->search($this->stations[3], $this->stations[1], $this->tomorrow))->toBe([])
        ->and($this->search->search($this->stations[1], $this->stations[3], $this->tomorrow->addDays(5)))->toBe([])
        ->and($this->search->search($this->stations[1], $this->stations[1], $this->tomorrow))->toBe([]);
});

it('matches an overnight train by the date the passenger boards', function () {
    // Departs 22:30; the third stop is reached after midnight (day_offset 1).
    $night = Train::factory()
        ->state(['train_route_id' => TrainRoute::factory()->withStops(4)])
        ->withTimetable('22:30')
        ->withCoaches(1)
        ->create();
    $run = TrainRun::factory()->for($night)->create(['journey_date' => $this->tomorrow->toDateString()]);
    $stations = $night->trainRoute->stops()->with('station')->get()->pluck('station');

    $results = $this->search->search($stations[2], $stations[3], $this->tomorrow->addDay());

    expect($results)->toHaveCount(1)
        ->and($results[0]->run->is($run))->toBeTrue()
        ->and($results[0]->departure->toDateString())->toBe($this->tomorrow->addDay()->toDateString())
        ->and($this->search->search($stations[2], $stations[3], $this->tomorrow))->toBe([]);
});

it('counts only seats free online for the segment and marks cancelled runs', function () {
    $seat = $this->train->rakeCoaches()->first()->coachType->seats()->first()->id;
    app(SeatInventoryService::class)->hold($this->run, [['coach_no' => 'KA', 'seat_id' => $seat]], 1, 3, Channel::Online);
    $this->train->rakeCoaches()->where('coach_no', 'KHA')->update(['counter_quota' => 2]);

    expect($this->search->search($this->stations[1], $this->stations[2], $this->tomorrow)[0]->availableSeats())->toBe(21)
        ->and($this->search->search($this->stations[2], $this->stations[3], $this->tomorrow)[0]->availableSeats())->toBe(22);

    $this->run->update(['status' => RunStatus::Cancelled]);
    $option = $this->search->search($this->stations[1], $this->stations[2], $this->tomorrow)[0];
    expect($option->isCancelled())->toBeTrue()
        ->and($option->isBookable())->toBeFalse();
});

describe('search page', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
    });

    it('is open to passengers, not to other staff or guests', function () {
        $this->get('/book')->assertRedirect('/login');

        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Planner->value))
            ->get('/book')->assertForbidden();

        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Passenger->value))
            ->get('/book')->assertOk()->assertSee('Book tickets');
    });

    it('searches and lists the train with its fare', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Passenger->value));

        Livewire::test(SearchTrains::class)
            ->set('from', $this->stations[0]->id)
            ->set('to', $this->stations[3]->id)
            ->set('date', $this->tomorrow->toDateString())
            ->call('search')
            ->assertHasNoErrors()
            ->assertSee($this->train->label())
            ->assertSee('৳120')
            ->assertSee('24 seats free');
    });

    it('validates the stations and the date range', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Passenger->value));

        Livewire::test(SearchTrains::class)
            ->set('from', $this->stations[0]->id)
            ->set('to', $this->stations[0]->id)
            ->set('date', now()->subDay()->toDateString())
            ->call('search')
            ->assertHasErrors(['to', 'date']);
    });

    it('fills the form from the URL and swaps stations', function () {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::Passenger->value));

        Livewire::withQueryParams(['from' => $this->stations[0]->id, 'to' => $this->stations[3]->id, 'date' => $this->tomorrow->toDateString()])
            ->test(SearchTrains::class)
            ->assertSee($this->train->label())
            ->call('swap')
            ->assertSet('from', $this->stations[3]->id)
            ->assertSet('to', $this->stations[0]->id);
    });
});
