<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function userWithRole(Role $role): User
{
    return tap(User::factory()->create())->assignRole($role->value);
}

it('creates all nine roles with their permissions', function () {
    expect(Spatie\Permission\Models\Role::pluck('name')->sort()->values()->all())
        ->toBe(collect(Role::values())->sort()->values()->all());

    expect(Spatie\Permission\Models\Permission::count())->toBe(count(Permission::cases()));
});

it('seeds one verified demo account per role', function () {
    Storage::fake('local'); // don't overwrite the real demo-credentials.txt
    config(['railway.demo_password' => 'demo-pass-123']);

    $this->seed(DatabaseSeeder::class);

    Storage::disk('local')->assertExists('demo-credentials.txt');

    foreach (Role::cases() as $role) {
        $user = User::where('email', "{$role->value}@railway-erp.test")->firstOrFail();

        expect($user->hasRole($role->value))->toBeTrue()
            ->and($user->hasVerifiedEmail())->toBeTrue();
    }

    $this->post(route('login.store'), [
        'email' => 'planner@railway-erp.test',
        'password' => 'demo-pass-123',
    ]);
    $this->assertAuthenticated();
});

it('lets every staff role open the back-office panel', function (Role $role) {
    $this->actingAs(userWithRole($role))
        ->get('/admin')
        ->assertOk();
})->with(Role::staff());

it('keeps passengers and guests out of the back-office panel', function () {
    $this->get('/admin')->assertRedirect('/admin/login');

    $this->actingAs(userWithRole(Role::Passenger))
        ->get('/admin')
        ->assertForbidden();
});

it('grants permissions according to the role matrix', function () {
    $planner = userWithRole(Role::Planner);
    $finance = userWithRole(Role::Finance);
    $tte = userWithRole(Role::Tte);
    $admin = userWithRole(Role::Admin);

    expect($planner->can(Permission::ManageNetwork->value))->toBeTrue()
        ->and($planner->can(Permission::ManagePayments->value))->toBeFalse()
        ->and($finance->can(Permission::ViewReports->value))->toBeTrue()
        ->and($finance->can(Permission::ManageNetwork->value))->toBeFalse()
        ->and($tte->can(Permission::CheckTickets->value))->toBeTrue()
        ->and($tte->can(Permission::CounterBooking->value))->toBeFalse()
        ->and($admin->can(Permission::ManageUsers->value))->toBeTrue()
        ->and($admin->can('anything.at.all'))->toBeTrue();
});

it('registers self sign-ups as passengers', function () {
    $this->post(route('register.store'), [
        'name' => 'New Passenger',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'new@example.com')->firstOrFail();

    expect($user->hasRole(Role::Passenger->value))->toBeTrue()
        ->and($user->isStaff())->toBeFalse();
});
