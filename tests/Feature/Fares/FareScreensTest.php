<?php

use App\Enums\Role;
use App\Enums\SeatClass;
use App\Filament\Resources\Concessions\Pages\ManageConcessions;
use App\Filament\Resources\FareRules\Pages\ManageFareRules;
use App\Models\Concession;
use App\Models\FareRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actingAsFareRole(Role $role): User
{
    $user = tap(User::factory()->create())->assignRole($role->value);
    test()->actingAs($user);

    return $user;
}

it('lets admin, finance and manager open the fare screens, but not a planner or clerk', function (Role $role, int $status) {
    actingAsFareRole($role);

    $this->get('/admin/fare-rules')->assertStatus($status);
    $this->get('/admin/concessions')->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'finance' => [Role::Finance, 200],
    'manager' => [Role::Manager, 200],
    'planner' => [Role::Planner, 403],
    'counter clerk' => [Role::CounterClerk, 403],
]);

it('lets an admin add a fare rule entered in taka and stores poisha', function () {
    actingAsFareRole(Role::Admin);

    Livewire::test(ManageFareRules::class)
        ->callAction(CreateAction::class, ['seat_class' => SeatClass::Snigdha->value, 'km_from' => 0, 'km_to' => 50, 'fare_poisha' => '105.50'])
        ->assertHasNoFormErrors();

    expect(FareRule::sole()->only(['km_from', 'km_to', 'fare_poisha']))->toBe(['km_from' => 0, 'km_to' => 50, 'fare_poisha' => 10550]);
});

it('rejects an overlapping band', function () {
    actingAsFareRole(Role::Admin);
    FareRule::create(['seat_class' => SeatClass::Snigdha, 'km_from' => 0, 'km_to' => 50, 'fare_poisha' => 10000]);

    Livewire::test(ManageFareRules::class)
        ->callAction(CreateAction::class, ['seat_class' => SeatClass::Snigdha->value, 'km_from' => 40, 'km_to' => 90, 'fare_poisha' => 150])
        ->assertHasFormErrors(['km_to']);

    expect(FareRule::count())->toBe(1);
});

it('lets an admin change a fare, and the change is audited', function () {
    $admin = actingAsFareRole(Role::Admin);
    $rule = FareRule::create(['seat_class' => SeatClass::Snigdha, 'km_from' => 0, 'km_to' => 50, 'fare_poisha' => 10000]);

    Livewire::test(ManageFareRules::class)
        ->callAction(TestAction::make(EditAction::class)->table($rule), ['fare_poisha' => 120])
        ->assertHasNoFormErrors();

    expect($rule->refresh()->fare_poisha)->toBe(12000)
        ->and(Activity::where('subject_id', $rule->id)->where('event', 'updated')->value('causer_id'))->toBe($admin->id);
});

it('shows finance the fares read-only', function () {
    actingAsFareRole(Role::Finance);
    $rule = FareRule::create(['seat_class' => SeatClass::Snigdha, 'km_from' => 0, 'km_to' => 50, 'fare_poisha' => 10000]);

    Livewire::test(ManageFareRules::class)
        ->assertCanSeeTableRecords([$rule])
        ->assertActionHidden(CreateAction::class)
        ->assertActionHidden(TestAction::make(EditAction::class)->table($rule));
});

it('lets an admin add a concession with an age limit', function () {
    actingAsFareRole(Role::Admin);

    Livewire::test(ManageConcessions::class)
        ->callAction(CreateAction::class, ['code' => 'student', 'name' => 'Student', 'percent' => 20, 'min_age' => 12, 'max_age' => 25, 'is_active' => true])
        ->assertHasNoFormErrors();

    expect(Concession::sole()->only(['code', 'percent', 'min_age', 'max_age']))
        ->toBe(['code' => 'STUDENT', 'percent' => 20, 'min_age' => 12, 'max_age' => 25]);
});
