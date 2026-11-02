<?php

use App\Enums\LocoStatus;
use App\Enums\MaintenanceType;
use App\Enums\Role;
use App\Enums\StaffDesignation;
use App\Filament\Resources\Locomotives\Pages\ManageLocomotives;
use App\Filament\Resources\Staff\Pages\ManageStaff;
use App\Models\Locomotive;
use App\Models\Staff;
use App\Models\User;
use App\Services\MaintenanceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actingAsCrewRole(Role $role): User
{
    $user = tap(User::factory()->create())->assignRole($role->value);
    test()->actingAs($user);

    return $user;
}

it('shows staff and locos to admin, crew controller and manager only', function (Role $role, int $status) {
    actingAsCrewRole($role);

    $this->get('/admin/staff')->assertStatus($status);
    $this->get('/admin/locomotives')->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'crew controller' => [Role::CrewController, 200],
    'manager' => [Role::Manager, 200],
    'planner' => [Role::Planner, 403],
    'tte' => [Role::Tte, 403],
]);

it('lets a crew controller add a driver, and requires a licence for drivers only', function () {
    actingAsCrewRole(Role::CrewController);

    Livewire::test(ManageStaff::class)
        ->callAction(CreateAction::class, ['staff_no' => 'BR20001', 'name' => 'Abdul Karim', 'designation' => StaffDesignation::Driver->value, 'is_active' => true])
        ->assertHasFormErrors(['licence_no' => 'required', 'licence_expires_on' => 'required']);

    Livewire::test(ManageStaff::class)
        ->callAction(CreateAction::class, ['staff_no' => 'BR20001', 'name' => 'Abdul Karim', 'designation' => StaffDesignation::Driver->value, 'licence_no' => 'DL-1', 'licence_expires_on' => now()->addYear()->toDateString(), 'is_active' => true])
        ->assertHasNoFormErrors()
        ->callAction(CreateAction::class, ['staff_no' => 'BR20002', 'name' => 'Nasrin Sultana', 'designation' => StaffDesignation::Guard->value, 'is_active' => true])
        ->assertHasNoFormErrors();

    expect(Staff::count())->toBe(2);
});

it('lets a manager look but not change', function () {
    $loco = Locomotive::factory()->create();
    actingAsCrewRole(Role::Manager);

    Livewire::test(ManageLocomotives::class)
        ->assertCanSeeTableRecords([$loco])
        ->assertActionHidden(CreateAction::class)
        ->assertActionHidden(TestAction::make('workshop')->table($loco));
});

it('sends a loco to the workshop and back, keeping the history', function () {
    $loco = Locomotive::factory()->create();
    actingAsCrewRole(Role::CrewController);

    Livewire::test(ManageLocomotives::class)
        ->callAction(TestAction::make('workshop')->table($loco), ['type' => MaintenanceType::Breakdown->value, 'started_on' => now()->subDay()->toDateString(), 'description' => 'Brake fault'])
        ->assertNotified("Loco {$loco->loco_no} is in the workshop");

    expect($loco->refresh()->status)->toBe(LocoStatus::UnderRepair)
        ->and($loco->maintenances()->sole()->isOpen())->toBeTrue();

    Livewire::test(ManageLocomotives::class)
        ->assertActionHidden(TestAction::make('workshop')->table($loco))
        ->callAction(TestAction::make('return')->table($loco), ['completed_on' => now()->toDateString(), 'next_due' => now()->addDays(90)->toDateString()]);

    $loco->refresh();
    expect($loco->status)->toBe(LocoStatus::Available)
        ->and($loco->last_maintenance_on->toDateString())->toBe(now()->toDateString())
        ->and($loco->next_maintenance_due->toDateString())->toBe(now()->addDays(90)->toDateString())
        ->and($loco->maintenances()->sole()->isOpen())->toBeFalse();

    // A loco with a workshop history can't be deleted.
    Livewire::test(ManageLocomotives::class)->assertActionHidden(TestAction::make(DeleteAction::class)->table($loco));
});

it('refuses workshop moves that make no sense', function () {
    $service = app(MaintenanceService::class);
    $loco = Locomotive::factory()->create();
    $retired = Locomotive::factory()->create(['status' => LocoStatus::Retired]);

    expect(fn () => $service->complete($loco, now(), now()->addDays(30)))->toThrow(DomainException::class, 'no open workshop visit')
        ->and(fn () => $service->start($retired, MaintenanceType::Scheduled, now()))->toThrow(DomainException::class, 'retired')
        ->and(fn () => $service->start($loco, MaintenanceType::Scheduled, now()->addDay()))->toThrow(DomainException::class, 'future');

    $service->start($loco, MaintenanceType::Scheduled, now()->subDays(2));

    expect(fn () => $service->start($loco, MaintenanceType::Breakdown, now()))->toThrow(DomainException::class, 'already in the workshop')
        ->and(fn () => $service->complete($loco, now()->subDays(3), now()->addDays(30)))->toThrow(DomainException::class, 'between the start date and today')
        ->and(fn () => $service->complete($loco, now(), now()))->toThrow(DomainException::class, 'due after');
});
