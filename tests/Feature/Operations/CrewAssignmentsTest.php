<?php

use App\Enums\LocoStatus;
use App\Enums\Role;
use App\Filament\Pages\CrewAssignments;
use App\Mail\ExpiryWarningsMail;
use App\Models\Locomotive;
use App\Models\Staff;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\CrewService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->run = TrainRun::factory()->create(['journey_date' => now()->toDateString()]);
    $this->controller = tap(User::factory()->create())->assignRole(Role::CrewController->value);
});

it('is open to admin, crew controller and manager', function (Role $role, int $status) {
    $this->actingAs(tap(User::factory()->create())->assignRole($role->value))
        ->get('/admin/crew-assignments')
        ->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'crew controller' => [Role::CrewController, 200],
    'manager' => [Role::Manager, 200],
    'tte' => [Role::Tte, 403],
]);

it('staffs a run from the page and shows when it is ready', function () {
    $driver = Staff::factory()->driver()->create();
    $guard = Staff::factory()->create();
    $loco = Locomotive::factory()->create();
    $this->actingAs($this->controller);

    Livewire::test(CrewAssignments::class)
        ->assertSee('3 to fix')
        ->call('selectRun', $this->run->id)
        ->set('staffId', $driver->id)->call('addCrew')
        ->set('staffId', $guard->id)->call('addCrew')
        ->set('locoId', $loco->id)->call('assignLoco')
        ->assertNotified("Loco {$loco->loco_no} assigned")
        ->assertSee('Ready')
        ->assertSee($driver->label());

    expect(app(CrewService::class)->problems($this->run))->toBe([]);
});

it('shows why an assignment is blocked', function () {
    $expired = Staff::factory()->driver()->licenceExpired()->create(['name' => 'Abdul Karim']);
    $repair = Locomotive::factory()->create(['loco_no' => '3020', 'status' => LocoStatus::UnderRepair]);
    $this->actingAs($this->controller);

    Livewire::test(CrewAssignments::class)
        ->call('selectRun', $this->run->id)
        ->assertSee('Abdul Karim ('.$expired->staff_no.') — licence expired')
        ->assertSee('3020 · '.$repair->model.' — Under repair')
        ->set('staffId', $expired->id)->call('addCrew')
        ->assertNotified('Not assigned')
        ->set('locoId', $repair->id)->call('assignLoco')
        ->assertNotified('Not assigned');

    expect($this->run->crewAssignments()->count())->toBe(0)
        ->and($this->run->locoAssignment()->exists())->toBeFalse();
});

it('lets a manager look but not assign', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Manager->value));

    Livewire::test(CrewAssignments::class)
        ->call('selectRun', $this->run->id)
        ->assertDontSee('Add crew member')
        ->set('staffId', Staff::factory()->create()->id)
        ->call('addCrew')
        ->assertForbidden();
});

it('emails crew controllers about expiring licences and maintenance due', function () {
    Mail::fake();
    Staff::factory()->driver()->licenceExpired()->create();
    Staff::factory()->driver()->create(['licence_expires_on' => now()->addDays(10)->toDateString()]);
    Staff::factory()->driver()->create(); // a year left: not listed
    Locomotive::factory()->create(['next_maintenance_due' => now()->addDays(3)->toDateString()]);
    Locomotive::factory()->create(['next_maintenance_due' => now()->subDay()->toDateString(), 'status' => LocoStatus::Retired]); // retired: not listed
    $admin = tap(User::factory()->create())->assignRole(Role::Admin->value);

    $this->artisan('crew:expiry-warnings')
        ->expectsOutputToContain('2 licence(s) and 1 loco(s) need attention')
        ->assertSuccessful();

    Mail::assertSent(ExpiryWarningsMail::class, 2);
    Mail::assertSent(ExpiryWarningsMail::class, fn (ExpiryWarningsMail $m) => $m->hasTo($this->controller->email) && $m->licences->count() === 2 && $m->locos->count() === 1);
    Mail::assertSent(ExpiryWarningsMail::class, fn (ExpiryWarningsMail $m) => $m->hasTo($admin->email));
});

it('sends nothing when there is nothing to warn about', function () {
    Mail::fake();
    Staff::factory()->driver()->create();

    $this->artisan('crew:expiry-warnings')->assertSuccessful();

    Mail::assertNothingSent();
});
