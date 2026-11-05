<?php

use App\Enums\Role;
use App\Filament\Pages\DemoData;
use App\Models\User;
use App\Services\DemoDataService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = tap(User::factory()->create())->assignRole(Role::Admin->value);
});

it('is for admins only', function (Role $role, int $status) {
    $this->actingAs(tap(User::factory()->create())->assignRole($role->value))
        ->get('/admin/demo-data')
        ->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'manager' => [Role::Manager, 403],
    'finance' => [Role::Finance, 403],
]);

it('is not available in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->actingAs($this->admin)->get('/admin/demo-data')->assertForbidden();
    expect(fn () => app(DemoDataService::class)->reset())->toThrow(RuntimeException::class, 'production');
    $this->artisan('demo:reset --force')->assertFailed();
});

it('resets after confirmation and sends the admin to log in again', function () {
    $this->mock(DemoDataService::class)->shouldReceive('reset')->once();

    Livewire::actingAs($this->admin)
        ->test(DemoData::class)
        ->assertSee('Bookings on past runs')
        ->callAction('reset')
        ->assertRedirect(filament()->getLoginUrl());

    $this->assertGuest();
});

it('can be run from the terminal', function () {
    $this->mock(DemoDataService::class)->shouldReceive('reset')->once();

    $this->artisan('demo:reset --force')->assertSuccessful();
});
