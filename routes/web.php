<?php

use App\Enums\Permission;
use App\Livewire\Booking\Checkout;
use App\Livewire\Booking\SearchTrains;
use App\Livewire\Booking\SelectSeats;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// Passenger portal: booking pages for accounts with bookings.own (passengers; admins pass every check).
Route::middleware(['auth', 'verified', 'can:'.Permission::BookOwnTickets->value])->prefix('book')->name('booking.')->group(function () {
    Route::livewire('/', SearchTrains::class)->name('search');
    Route::livewire('runs/{run}/seats/{from}/{to}', SelectSeats::class)->whereNumber(['from', 'to'])->name('seats');
    Route::livewire('checkout/{booking}', Checkout::class)->name('checkout');
});

require __DIR__.'/settings.php';
