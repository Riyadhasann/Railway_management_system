<?php

use App\Enums\Permission;
use App\Livewire\Booking\SearchTrains;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

// Passenger portal: booking pages for accounts with bookings.own (passengers; admins pass every check).
Route::middleware(['auth', 'verified', 'can:'.Permission::BookOwnTickets->value])->prefix('book')->name('booking.')->group(function () {
    Route::livewire('/', SearchTrains::class)->name('search');
});

require __DIR__.'/settings.php';
