<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\RefundRules\Pages\ManageRefundRules;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function actingAsFinanceRole(Role $role): User
{
    $user = tap(User::factory()->create())->assignRole($role->value);
    test()->actingAs($user);

    return $user;
}

function failedRefund(): Refund
{
    $booking = Booking::factory()->confirmed()->create();
    $payment = Payment::create([
        'booking_id' => $booking->id, 'method' => PaymentMethod::Bkash, 'reference' => 'PAYTEST1', 'txn_id' => 'MOCKT1',
        'amount_poisha' => 50000, 'status' => PaymentStatus::Success, 'paid_at' => now(),
    ]);

    return Refund::create([
        'booking_id' => $booking->id, 'payment_id' => $payment->id, 'reason' => RefundReason::TrainCancelled,
        'amount_poisha' => 50000, 'percent' => 100, 'status' => RefundStatus::Failed, 'failure_reason' => 'Gateway timeout',
    ]);
}

it('shows transactions and refunds to admin, finance and manager only', function (Role $role, int $status) {
    actingAsFinanceRole($role);

    $this->get('/admin/payments')->assertStatus($status);
    $this->get('/admin/refunds')->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'finance' => [Role::Finance, 200],
    'manager' => [Role::Manager, 200],
    'counter clerk' => [Role::CounterClerk, 403],
    'planner' => [Role::Planner, 403],
]);

it('lists payments with their PNR', function () {
    actingAsFinanceRole(Role::Finance);
    $refund = failedRefund();

    Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$refund->payment])->assertSee($refund->booking->pnr);
});

it('lets finance retry a failed refund, but not a manager', function () {
    $refund = failedRefund();

    actingAsFinanceRole(Role::Manager);
    Livewire::test(ListRefunds::class)->assertActionHidden(TestAction::make('retry')->table($refund));

    actingAsFinanceRole(Role::Finance);
    Livewire::test(ListRefunds::class)->callAction(TestAction::make('retry')->table($refund));

    expect($refund->refresh()->status)->toBe(RefundStatus::Processed)
        ->and($refund->payment->refresh()->status)->toBe(PaymentStatus::Refunded);
});

it('lets an admin edit refund rules, finance and manager only view them', function (Role $role, int $status) {
    actingAsFinanceRole($role);

    $this->get('/admin/refund-rules')->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'finance' => [Role::Finance, 200],
    'manager' => [Role::Manager, 200],
    'counter clerk' => [Role::CounterClerk, 403],
]);

it('adds a refund rule with the fee entered in taka', function () {
    actingAsFinanceRole(Role::Admin);

    Livewire::test(ManageRefundRules::class)
        ->callAction(CreateAction::class, ['hours_before' => 72, 'percent' => 95, 'fee_poisha' => '15.50'])
        ->assertHasNoFormErrors()
        ->callAction(CreateAction::class, ['hours_before' => 72, 'percent' => 10, 'fee_poisha' => '0'])
        ->assertHasFormErrors(['hours_before' => 'unique']);

    expect(RefundRule::sole()->only(['hours_before', 'percent', 'fee_poisha']))->toBe(['hours_before' => 72, 'percent' => 95, 'fee_poisha' => 1550]);

    actingAsFinanceRole(Role::Finance);
    Livewire::test(ManageRefundRules::class)->assertActionHidden(CreateAction::class);
});

it('opens the counter cancellation page for clerks only', function (Role $role, int $status) {
    actingAsFinanceRole($role);

    $this->get('/admin/counter-cancellation')->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'counter clerk' => [Role::CounterClerk, 200],
    'finance' => [Role::Finance, 403],
]);
