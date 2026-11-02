<?php

use App\Enums\LocoStatus;
use App\Enums\StaffDesignation;
use App\Models\Locomotive;
use App\Models\Staff;
use Database\Seeders\DemoNetworkSeeder;
use Database\Seeders\DemoStaffSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, DemoUsersSeeder::class, DemoNetworkSeeder::class, DemoStaffSeeder::class]);
});

it('seeds 30 crew and 8 locos, and is safe to run again', function () {
    $this->seed(DemoStaffSeeder::class);

    expect(Staff::count())->toBe(30)
        ->and(Staff::where('designation', StaffDesignation::Driver->value)->count())->toBe(10)
        ->and(Locomotive::count())->toBe(8)
        ->and(Staff::whereNull('home_station_id')->count())->toBe(0);
});

it('includes the cases the crew rules need: an expired licence, a loco under repair, maintenance due soon', function () {
    $today = now();

    expect(Staff::get()->reject->hasValidLicence($today)->pluck('staff_no')->all())->toBe(['BR10001'])
        ->and(Staff::where('designation', StaffDesignation::Guard->value)->whereNotNull('licence_no')->count())->toBe(0)
        ->and(Locomotive::where('status', LocoStatus::UnderRepair->value)->sole()->maintenances()->sole()->isOpen())->toBeTrue()
        ->and(Locomotive::whereDate('next_maintenance_due', '<=', now()->addDays(7)->toDateString())->pluck('loco_no')->sort()->values()->all())->toBe(['2902', '3011']);
});

it('links the demo TTE login to a TTE on the crew list', function () {
    $tte = Staff::whereNotNull('user_id')->sole();

    expect($tte->designation)->toBe(StaffDesignation::Tte)
        ->and($tte->user->email)->toBe('tte@railway-erp.test');
});
