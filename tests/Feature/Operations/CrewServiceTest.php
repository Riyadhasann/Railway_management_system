<?php

use App\Enums\LocoStatus;
use App\Enums\MaintenanceType;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Enums\StaffDesignation;
use App\Models\CrewAssignment;
use App\Models\Locomotive;
use App\Models\Staff;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\CrewService;
use App\Services\MaintenanceService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->crew = app(CrewService::class);
    $this->controller = tap(User::factory()->create())->assignRole(Role::CrewController->value);
    $this->run = TrainRun::factory()->create(['journey_date' => now()->addDays(2)->toDateString()]);
});

it('assigns a driver, a guard and a loco, and then has nothing left to fix', function () {
    expect($this->crew->problems($this->run))->toBe(['No driver assigned.', 'No guard assigned.', 'No locomotive assigned.']);

    $driver = Staff::factory()->driver()->create();
    $assignment = $this->crew->assignCrew($this->run, $driver, $this->controller);
    $this->crew->assignCrew($this->run, Staff::factory()->create(), $this->controller);
    $this->crew->assignLoco($this->run, Locomotive::factory()->create(), $this->controller);

    expect($assignment->duty)->toBe(StaffDesignation::Driver)
        ->and($assignment->assigned_by)->toBe($this->controller->id)
        ->and($this->crew->problems($this->run))->toBe([]);
});

it('blocks a driver whose licence has expired, or expires before the journey', function () {
    $expired = Staff::factory()->driver()->licenceExpired()->create(['name' => 'Abdul Karim']);
    $expiresTomorrow = Staff::factory()->driver()->create(['licence_expires_on' => now()->addDay()->toDateString()]);
    $noLicence = Staff::factory()->create(['designation' => StaffDesignation::AssistantDriver]);

    expect(fn () => $this->crew->assignCrew($this->run, $expired, $this->controller))->toThrow(DomainException::class, "Abdul Karim's driving licence expired on")
        ->and(fn () => $this->crew->assignCrew($this->run, $expiresTomorrow, $this->controller))->toThrow(DomainException::class, 'expired on')
        ->and(fn () => $this->crew->assignCrew($this->run, $noLicence, $this->controller))->toThrow(DomainException::class, 'no driving licence')
        ->and(CrewAssignment::count())->toBe(0);
});

it('blocks a loco that is under repair or retired', function () {
    $loco = Locomotive::factory()->create(['loco_no' => '3020']);
    app(MaintenanceService::class)->start($loco, MaintenanceType::Breakdown, now());

    expect(fn () => $this->crew->assignLoco($this->run, $loco, $this->controller))->toThrow(DomainException::class, 'Loco 3020 is Under repair')
        ->and(fn () => $this->crew->assignLoco($this->run, Locomotive::factory()->create(['status' => LocoStatus::Retired]), $this->controller))->toThrow(DomainException::class, 'Retired');
});

it('flags an assigned loco that has since gone into the workshop', function () {
    $loco = Locomotive::factory()->create(['loco_no' => '2902']);
    $this->crew->assignLoco($this->run, $loco, $this->controller);
    app(MaintenanceService::class)->start($loco, MaintenanceType::Breakdown, now());

    expect($this->crew->problems($this->run))->toContain('Loco 2902 is now Under repair: assign another.');
});

it('keeps a person and a loco to one train per day', function () {
    $sameDay = TrainRun::factory()->create(['journey_date' => $this->run->journey_date->toDateString()]);
    $nextDay = TrainRun::factory()->create(['journey_date' => $this->run->journey_date->addDay()->toDateString()]);
    $guard = Staff::factory()->create();
    $loco = Locomotive::factory()->create();

    $this->crew->assignCrew($this->run, $guard, $this->controller);
    $this->crew->assignLoco($this->run, $loco, $this->controller);

    expect(fn () => $this->crew->assignCrew($this->run, $guard, $this->controller))->toThrow(DomainException::class, 'already on this train')
        ->and(fn () => $this->crew->assignCrew($sameDay, $guard, $this->controller))->toThrow(DomainException::class, 'already working train')
        ->and(fn () => $this->crew->assignLoco($sameDay, $loco, $this->controller))->toThrow(DomainException::class, 'already hauling');

    $this->crew->assignCrew($nextDay, $guard, $this->controller);
    $this->crew->assignLoco($nextDay, $loco, $this->controller);
    $this->crew->assignLoco($this->run, $loco, $this->controller); // re-assigning the same loco to its own run is fine

    expect(CrewAssignment::where('staff_id', $guard->id)->count())->toBe(2);
});

it('replaces the loco on a run and can remove crew and loco', function () {
    $first = Locomotive::factory()->create();
    $second = Locomotive::factory()->create();
    $assignment = $this->crew->assignCrew($this->run, Staff::factory()->create(), $this->controller);

    $this->crew->assignLoco($this->run, $first, $this->controller);
    $this->crew->assignLoco($this->run, $second, $this->controller);
    expect($this->run->locoAssignment()->sole()->locomotive_id)->toBe($second->id);

    $this->crew->unassignCrew($assignment);
    $this->crew->unassignLoco($this->run);
    expect($this->run->crewAssignments()->count())->toBe(0)
        ->and($this->run->locoAssignment()->exists())->toBeFalse();
});

it('refuses changes to a cancelled or arrived run, and to inactive staff', function () {
    $inactive = Staff::factory()->create(['is_active' => false]);
    expect(fn () => $this->crew->assignCrew($this->run, $inactive, $this->controller))->toThrow(DomainException::class, 'not active');

    $this->run->update(['status' => RunStatus::Cancelled]);
    expect(fn () => $this->crew->assignCrew($this->run, Staff::factory()->create(), $this->controller))->toThrow(DomainException::class, 'Cancelled')
        ->and(fn () => $this->crew->assignLoco($this->run, Locomotive::factory()->create(), $this->controller))->toThrow(DomainException::class, 'Cancelled');
});
