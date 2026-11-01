<?php

use App\Enums\Role;
use App\Enums\SeatBookingStatus;
use App\Enums\SeatClass;
use App\Filament\Resources\CoachTypes\Pages\CreateCoachType;
use App\Filament\Resources\CoachTypes\Pages\EditSeatLayout;
use App\Filament\Resources\TrainRuns\Pages\ViewSeatMap;
use App\Filament\Resources\Trains\Pages\EditTrain;
use App\Models\CoachType;
use App\Models\Seat;
use App\Models\SeatBooking;
use App\Models\Train;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\SeatInventoryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function loginAs(Role $role): User
{
    $user = tap(User::factory()->create())->assignRole($role->value);
    test()->actingAs($user);

    return $user;
}

describe('coach types and the seat layout editor', function () {
    it('creates a coach type and opens its layout editor', function () {
        loginAs(Role::Planner);

        Livewire::test(CreateCoachType::class)
            ->fillForm(['code' => 'test_chair', 'name' => 'Test chair', 'seat_class' => SeatClass::AcChair->value, 'layout_rows' => 4, 'layout_cols' => 5])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin/coach-types/'.CoachType::where('code', 'TEST_CHAIR')->value('id').'/layout');
    });

    it('paints seats, berths and flags, then saves them', function () {
        loginAs(Role::Planner);
        $type = CoachType::factory()->create(['layout_rows' => 2, 'layout_cols' => 3, 'seat_class' => SeatClass::AcBerth]);

        Livewire::test(EditSeatLayout::class, ['record' => $type->id])
            ->call('paint', 1, 1)                       // seat 1
            ->call('selectTool', 'upper')->call('paint', 1, 2)  // seat 2, upper berth
            ->call('selectTool', 'women')->call('paint', 1, 1)  // flag seat 1
            ->call('selectTool', 'seat')->call('paint', 2, 3)   // seat 3
            ->call('selectTool', 'erase')->call('paint', 2, 3)  // remove it again
            ->assertSet('dirty', true)
            ->call('save')
            ->assertSet('dirty', false);

        $seats = $type->seats()->get();
        expect($seats)->toHaveCount(2)
            ->and($seats[0]->only(['seat_no', 'row', 'col', 'berth']))->toBe(['seat_no' => '1', 'row' => 1, 'col' => 1, 'berth' => null])
            ->and($seats[0]->hasFlag(Seat::FLAG_WOMEN))->toBeTrue()
            ->and($seats[1]->only(['seat_no', 'berth']))->toBe(['seat_no' => '2', 'berth' => 'upper']);
    });

    it('fills a whole coach with the pattern action', function () {
        loginAs(Role::Planner);
        $type = CoachType::factory()->create(['layout_rows' => 10, 'layout_cols' => 5]);

        Livewire::test(EditSeatLayout::class, ['record' => $type->id])
            ->callAction('fill', ['aisles' => ['3']])
            ->call('save');

        expect($type->seats()->count())->toBe(40);
    });

    it('will not remove a seat that has bookings or blocks', function () {
        loginAs(Role::Planner);
        $train = Train::factory()->withCoaches(1)->create();
        $type = $train->rakeCoaches()->first()->coachType;
        $seat = $type->seats()->first();
        SeatBooking::create([
            'train_run_id' => TrainRun::factory()->for($train)->create()->id,
            'coach_no' => 'KA', 'seat_id' => $seat->id, 'from_seq' => 1, 'to_seq' => 2, 'status' => SeatBookingStatus::Blocked,
        ]);

        Livewire::test(EditSeatLayout::class, ['record' => $type->id])
            ->call('selectTool', 'erase')
            ->call('paint', $seat->row, $seat->col)
            ->call('save')
            ->assertSet('dirty', true); // not saved

        expect($seat->fresh())->not->toBeNull();
    });

    it('lets managers see coach types but not edit layouts', function () {
        loginAs(Role::Manager);
        $type = CoachType::factory()->withSeats()->create();

        $this->get('/admin/coach-types')->assertOk();
        $this->get("/admin/coach-types/{$type->id}/layout")->assertForbidden();
    });
});

describe('train coaches (rake)', function () {
    it('adds coaches to a train in order, with counter quotas', function () {
        loginAs(Role::Planner);
        $train = Train::factory()->withTimetable()->create();
        $chair = CoachType::factory()->withSeats()->create();

        Livewire::test(EditTrain::class, ['record' => $train->id])
            ->set('data.rakeCoaches', [
                'a' => ['coach_no' => 'ka', 'coach_type_id' => $chair->id, 'counter_quota' => 4],
                'b' => ['coach_no' => 'kha', 'coach_type_id' => $chair->id, 'counter_quota' => 0],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($train->rakeCoaches()->get()->map->only(['coach_no', 'position', 'counter_quota'])->all())->toBe([
            ['coach_no' => 'KA', 'position' => 1, 'counter_quota' => 4],
            ['coach_no' => 'KHA', 'position' => 2, 'counter_quota' => 0],
        ]);
    });

    it('rejects a counter quota larger than the coach', function () {
        loginAs(Role::Planner);
        $train = Train::factory()->withTimetable()->create();
        $chair = CoachType::factory()->withSeats()->create(); // 12 seats

        Livewire::test(EditTrain::class, ['record' => $train->id])
            ->set('data.rakeCoaches', ['a' => ['coach_no' => 'KA', 'coach_type_id' => $chair->id, 'counter_quota' => 13]])
            ->call('save')
            ->assertHasFormErrors(['rakeCoaches.a.counter_quota']);
    });

    it('will not remove a coach that has blocked seats', function () {
        loginAs(Role::Planner);
        $train = Train::factory()->withTimetable()->withCoaches(2)->create();
        $coach = $train->rakeCoaches()->first();
        SeatBooking::create([
            'train_run_id' => TrainRun::factory()->for($train)->create()->id,
            'coach_no' => 'KA', 'seat_id' => $coach->coachType->seats()->first()->id,
            'from_seq' => 1, 'to_seq' => 2, 'status' => SeatBookingStatus::Blocked,
        ]);

        Livewire::test(EditTrain::class, ['record' => $train->id])
            ->set('data.rakeCoaches', ['b' => ['coach_no' => 'KHA', 'coach_type_id' => $coach->coach_type_id, 'counter_quota' => 0]])
            ->call('save')
            ->assertHasFormErrors(['rakeCoaches']);

        expect($train->rakeCoaches()->count())->toBe(2);
    });
});

describe('run seat map', function () {
    beforeEach(function () {
        $this->train = Train::factory()->withTimetable()->withCoaches(2)->create();
        $this->run = TrainRun::factory()->for($this->train)->create();
        $this->seat = $this->train->rakeCoaches()->first()->coachType->seats()->first();
    });

    it('renders every coach of the run', function () {
        loginAs(Role::Manager);

        $this->get("/admin/train-runs/{$this->run->id}/seats")
            ->assertOk()
            ->assertSee('KA')
            ->assertSee('KHA')
            ->assertSee('12 / 12 free');
    });

    it('lets a planner select seats and block them with a reason', function () {
        loginAs(Role::Planner);

        Livewire::test(ViewSeatMap::class, ['record' => $this->run->id])
            ->call('toggleSeat', 'KA', $this->seat->id)
            ->assertSet('selected', ["KA|{$this->seat->id}"])
            ->callAction('block', ['reason' => 'Damaged seat'])
            ->assertSet('selected', []);

        expect(SeatBooking::where('status', 'blocked')->where('coach_no', 'KA')->where('seat_id', $this->seat->id)->value('reason'))
            ->toBe('Damaged seat');
    });

    it('lets a planner unblock seats', function () {
        $planner = loginAs(Role::Planner);
        app(SeatInventoryService::class)->block($this->run, 'KA', [$this->seat->id], 1, 3, $planner);

        Livewire::test(ViewSeatMap::class, ['record' => $this->run->id])
            ->call('toggleSeat', 'KA', $this->seat->id)
            ->callAction('unblock');

        expect(SeatBooking::where('status', 'blocked')->count())->toBe(0);
    });

    it('does not let a manager block seats', function () {
        loginAs(Role::Manager);

        Livewire::test(ViewSeatMap::class, ['record' => $this->run->id])
            ->assertActionHidden('block')
            ->call('toggleSeat', 'KA', $this->seat->id)
            ->assertSet('selected', []);
    });

    it('hides the seat map from roles without network access', function () {
        loginAs(Role::Tte);

        $this->get("/admin/train-runs/{$this->run->id}/seats")->assertForbidden();
    });
});
