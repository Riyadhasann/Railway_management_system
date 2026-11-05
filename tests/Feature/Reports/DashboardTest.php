<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Filament\Widgets\OccupancyChart;
use App\Filament\Widgets\RevenueByChannelChart;
use App\Filament\Widgets\RevenueByTrainChart;
use App\Filament\Widgets\RevenueChart;
use App\Filament\Widgets\RevenueStats;
use App\Filament\Widgets\SalesByClassChart;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

dataset('widgets', [RevenueStats::class, RevenueChart::class, RevenueByChannelChart::class, SalesByClassChart::class, RevenueByTrainChart::class, OccupancyChart::class]);

it('shows the report widgets to admin, finance and manager only', function (Role $role, bool $sees) {
    $this->actingAs(tap(User::factory()->create())->assignRole($role->value));

    foreach ([RevenueStats::class, RevenueChart::class, OccupancyChart::class] as $widget) {
        expect($widget::canView())->toBe($sees);
    }

    $response = $this->get('/admin')->assertOk();
    $sees ? $response->assertSee('Revenue today') : $response->assertDontSee('Revenue today');
})->with([
    'admin' => [Role::Admin, true],
    'finance' => [Role::Finance, true],
    'manager' => [Role::Manager, true],
    'counter clerk' => [Role::CounterClerk, false],
    'tte' => [Role::Tte, false],
]);

it('renders every widget with no data at all', function (string $widget) {
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Finance->value));

    Livewire::test($widget)->assertOk();
})->with('widgets');

it('shows today\'s revenue from today\'s payments', function () {
    $booking = Booking::factory()->confirmed()->create();
    Payment::create(['booking_id' => $booking->id, 'method' => PaymentMethod::Cash, 'reference' => 'CSHTEST', 'amount_poisha' => 125000, 'status' => PaymentStatus::Success, 'paid_at' => now()]);
    $this->actingAs(tap(User::factory()->create())->assignRole(Role::Manager->value));

    Livewire::test(RevenueStats::class)->assertSee('৳1,250');
    Livewire::test(RevenueByChannelChart::class)->set('filter', '7')->assertOk();
});
