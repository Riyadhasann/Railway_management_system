<?php

namespace App\Livewire\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ScheduleStop;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Passenger portal: the signed-in passenger's bookings, newest first.
 *
 * @property-read Collection<int, Booking> $bookings
 */
#[Title('My bookings')]
class MyBookings extends Component
{
    /**
     * @return Collection<int, Booking>
     */
    #[Computed]
    public function bookings(): Collection
    {
        return Booking::query()
            ->where('user_id', auth()->id())
            // Abandoned holds are noise here.
            ->whereNotIn('status', [BookingStatus::Expired->value, BookingStatus::Failed->value])
            ->with(['trainRun.train.scheduleStops.routeStop.station'])
            ->withCount('passengers')
            ->latest('id')
            ->limit(50)
            ->get();
    }

    public function stationName(Booking $booking, int $sequence): string
    {
        return $booking->trainRun->train->scheduleStops
            ->first(fn (ScheduleStop $s) => $s->routeStop->sequence === $sequence)
            ?->routeStop->station->name ?? '';
    }

    public function render(): View
    {
        return view('livewire.booking.my-bookings');
    }
}
