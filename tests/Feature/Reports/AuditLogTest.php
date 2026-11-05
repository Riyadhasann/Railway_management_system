<?php

use App\Enums\Role;
use App\Enums\SeatClass;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\FareRules\Pages\ManageFareRules;
use App\Models\FareRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = tap(User::factory()->create(['name' => 'Nusrat Admin']))->assignRole(Role::Admin->value);
});

it('is for admins only', function (Role $role, int $status) {
    $this->actingAs(tap(User::factory()->create())->assignRole($role->value))
        ->get('/admin/audit-log')
        ->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'finance' => [Role::Finance, 403],
    'manager' => [Role::Manager, 403],
    'planner' => [Role::Planner, 403],
]);

it('shows who changed a fare rule, and what changed', function () {
    $rule = FareRule::create(['seat_class' => SeatClass::Snigdha, 'km_from' => 0, 'km_to' => 50, 'fare_poisha' => 10000]);
    $this->actingAs($this->admin);

    Livewire::test(ManageFareRules::class)
        ->callAction(TestAction::make(EditAction::class)->table($rule), ['fare_poisha' => '125'])
        ->assertHasNoFormErrors();

    $entry = Activity::query()->where('subject_type', $rule->getMorphClass())->where('event', 'updated')->sole();

    expect($entry->causer->is($this->admin))->toBeTrue()
        ->and(AuditLogResource::changes($entry))->toBe(['fare_poisha' => [10000, 12500]])
        ->and(AuditLogResource::summary($entry))->toBe('Fare Poisha: 10000 → 12500');

    Livewire::test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$entry])
        ->assertSee('Nusrat Admin')
        ->assertSee('Fare Rule #'.$rule->id)
        ->filterTable('event', 'updated')
        ->assertCanSeeTableRecords([$entry])
        ->callAction(TestAction::make('details')->table($entry))
        ->assertSee('12500');
});

it('never shows password fields', function () {
    $entry = Activity::create([
        'description' => 'updated',
        'event' => 'updated',
        'attribute_changes' => ['attributes' => ['name' => 'New', 'password' => 'secret-hash'], 'old' => ['name' => 'Old', 'password' => 'old-hash']],
    ]);

    expect(AuditLogResource::changes($entry))->toBe(['name' => ['Old', 'New']]);
});
