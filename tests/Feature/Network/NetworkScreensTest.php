<?php

use App\Enums\Role;
use App\Enums\RunStatus;
use App\Filament\Resources\Stations\Pages\CreateStation;
use App\Filament\Resources\Stations\Pages\EditStation;
use App\Filament\Resources\TrainRoutes\Pages\CreateTrainRoute;
use App\Filament\Resources\TrainRuns\Pages\ListTrainRuns;
use App\Filament\Resources\Trains\Pages\CreateTrain;
use App\Models\Station;
use App\Models\Train;
use App\Models\TrainRoute;
use App\Models\TrainRun;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actingAsRole(Role $role): User
{
    $user = tap(User::factory()->create())->assignRole($role->value);
    test()->actingAs($user);

    return $user;
}

describe('access by role', function () {
    $pages = ['/admin/stations', '/admin/train-routes', '/admin/trains', '/admin/train-runs'];

    it('lets network viewers open the network lists', function (Role $role) use ($pages) {
        actingAsRole($role);

        foreach ($pages as $page) {
            $this->get($page)->assertOk();
        }
    })->with([Role::Admin, Role::Planner, Role::Manager, Role::StationMaster, Role::CounterClerk, Role::CrewController]);

    it('hides the network from roles without network permissions', function (Role $role) use ($pages) {
        actingAsRole($role);

        foreach ($pages as $page) {
            $this->get($page)->assertForbidden();
        }
    })->with([Role::Tte, Role::Finance]);

    it('lets only planners and admins create network records', function () {
        actingAsRole(Role::Manager);
        $this->get('/admin/stations/create')->assertForbidden();

        actingAsRole(Role::Planner);
        $this->get('/admin/stations/create')->assertOk();
    });
});

describe('stations', function () {
    it('lets a planner create a station', function () {
        actingAsRole(Role::Planner);

        Livewire::test(CreateStation::class)
            ->fillForm(['code' => 'syl', 'name' => 'Sylhet', 'division' => 'Sylhet', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Station::where('code', 'SYL')->exists())->toBeTrue();
    });

    it('rejects a duplicate station code', function () {
        actingAsRole(Role::Planner);
        Station::factory()->create(['code' => 'DHK']);

        Livewire::test(CreateStation::class)
            ->fillForm(['code' => 'DHK', 'name' => 'Another'])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);
    });

    it('does not allow deleting a station that is on a route, even for admins', function () {
        actingAsRole(Role::Admin);
        $route = TrainRoute::factory()->withStops(2)->create();
        $used = $route->stops()->first()->station;
        $unused = Station::factory()->create();

        Livewire::test(EditStation::class, ['record' => $used->getRouteKey()])->assertActionHidden('delete');
        Livewire::test(EditStation::class, ['record' => $unused->getRouteKey()])->assertActionVisible('delete');
    });
});

describe('routes', function () {
    it('creates a route with ordered stops', function () {
        actingAsRole(Role::Planner);
        [$a, $b, $c] = Station::factory()->count(3)->create();

        Livewire::test(CreateTrainRoute::class)
            ->fillForm(['code' => 'a-c', 'name' => 'A to C', 'is_active' => true])
            ->set('data.stops', [
                'one' => ['station_id' => $a->id, 'distance_km' => 0],
                'two' => ['station_id' => $b->id, 'distance_km' => 40],
                'three' => ['station_id' => $c->id, 'distance_km' => 90],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $route = TrainRoute::where('code', 'A-C')->firstOrFail();
        expect($route->stops()->pluck('station_id')->all())->toBe([$a->id, $b->id, $c->id])
            ->and($route->stops()->pluck('sequence')->all())->toBe([1, 2, 3]);
    });

    it('rejects distances that do not increase', function () {
        actingAsRole(Role::Planner);
        [$a, $b] = Station::factory()->count(2)->create();

        Livewire::test(CreateTrainRoute::class)
            ->fillForm(['code' => 'bad', 'name' => 'Bad'])
            ->set('data.stops', [
                'one' => ['station_id' => $a->id, 'distance_km' => 10],
                'two' => ['station_id' => $b->id, 'distance_km' => 5],
            ])
            ->call('create')
            ->assertHasFormErrors(['stops']);

        expect(TrainRoute::where('code', 'BAD')->exists())->toBeFalse();
    });
});

describe('trains', function () {
    it('creates a train with an overnight timetable', function () {
        actingAsRole(Role::Planner);
        $route = TrainRoute::factory()->withStops(3)->create();
        [$s1, $s2, $s3] = $route->stops()->get();

        Livewire::test(CreateTrain::class)
            ->fillForm([
                'number' => '999',
                'name' => 'Test Night Mail',
                'train_route_id' => $route->id,
                'running_days' => [1, 3, 5],
                'is_active' => true,
            ])
            ->set('data.scheduleStops', [
                'a' => ['route_stop_id' => $s1->id, 'arrival_time' => null, 'departure_time' => '23:00', 'day_offset' => 0],
                'b' => ['route_stop_id' => $s2->id, 'arrival_time' => '00:30', 'departure_time' => '00:35', 'day_offset' => 1],
                'c' => ['route_stop_id' => $s3->id, 'arrival_time' => '02:00', 'departure_time' => null, 'day_offset' => 1],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $train = Train::where('number', '999')->firstOrFail();
        expect($train->runningDays())->toBe([1, 3, 5])
            ->and($train->scheduleStops()->count())->toBe(3)
            ->and($train->scheduleStops()->get()->last()->day_offset)->toBe(1);
    });

    it('fills the timetable with the route stations when a route is picked', function () {
        actingAsRole(Role::Planner);
        $route = TrainRoute::factory()->withStops(4)->create();

        $component = Livewire::test(CreateTrain::class)->fillForm(['train_route_id' => $route->id]);

        $stops = $component->get('data.scheduleStops');
        expect(array_column(array_values($stops), 'route_stop_id'))->toBe($route->stops()->pluck('id')->all());
    });

    it('rejects an overnight timetable without day offsets', function () {
        actingAsRole(Role::Planner);
        $route = TrainRoute::factory()->withStops(2)->create();
        [$s1, $s2] = $route->stops()->get();

        Livewire::test(CreateTrain::class)
            ->fillForm(['number' => '998', 'name' => 'Broken', 'train_route_id' => $route->id, 'running_days' => [1]])
            ->set('data.scheduleStops', [
                'a' => ['route_stop_id' => $s1->id, 'arrival_time' => null, 'departure_time' => '23:00', 'day_offset' => 0],
                'b' => ['route_stop_id' => $s2->id, 'arrival_time' => '01:00', 'departure_time' => null, 'day_offset' => 0],
            ])
            ->call('create')
            ->assertHasFormErrors(['scheduleStops']);
    });
});

describe('train runs', function () {
    it('lets a planner generate runs from the list page', function () {
        actingAsRole(Role::Planner);
        Train::factory()->create();

        Livewire::test(ListTrainRuns::class)->callAction('generate');

        expect(TrainRun::count())->toBe(config('railway.runs_ahead_days'));
    });

    it('lets a planner cancel a run with a reason', function () {
        actingAsRole(Role::Planner);
        $run = TrainRun::factory()->create();

        Livewire::test(ListTrainRuns::class)
            ->callTableAction('cancel', $run, ['reason' => 'Flooding near Akhaura']);

        expect($run->fresh())
            ->status->toBe(RunStatus::Cancelled)
            ->cancel_reason->toBe('Flooding near Akhaura');
    });

    it('does not let a manager generate or cancel runs', function () {
        actingAsRole(Role::Manager);
        $run = TrainRun::factory()->create();

        Livewire::test(ListTrainRuns::class)
            ->assertActionHidden('generate')
            ->assertTableActionHidden('cancel', $run);
    });
});
