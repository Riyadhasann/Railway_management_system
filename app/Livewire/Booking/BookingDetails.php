<?php

namespace App\Livewire\Booking;

use App\Data\RefundQuote;
use App\Enums\BookingStatus;
use App\Enums\RefundReason;
use App\Exceptions\BookingRefusedException;
use App\Models\Booking;
use App\Models\ScheduleStop;
use App\Models\User;
use App\Services\RefundService;
use App\Services\SeatInventoryService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Passenger portal: one booking with its ticket, payments and refunds, and online cancellation.
 *
 * @property-read Collection<int, ScheduleStop> $stops
 */
#[Title('Booking')]
class BookingDetails extends Component
{
    #[Locked]
    public Booking $booking;

    public ?string $notice = null;

    public function mount(Booking $booking): void
    {
        abort_unless($booking->user_id === auth()->id(), 403);

        $this->booking = $booking;
    }

    /**
     * @return Collection<int, ScheduleStop>
     */
    #[Computed]
    public function stops(): Collection
    {
        return $this->booking->trainRun->train->scheduleStops()->with('routeStop.station')->get()
            ->keyBy(fn (ScheduleStop $s) => $s->routeStop->sequence);
    }

    public function departure(): ?CarbonImmutable
    {
        return $this->stops->get($this->booking->from_seq)?->departureOn($this->booking->trainRun->journey_date);
    }

    public function arrival(): ?CarbonImmutable
    {
        return $this->stops->get($this->booking->to_seq)?->arrivalOn($this->booking->trainRun->journey_date);
    }

    public function canCancel(): bool
    {
        return $this->booking->status === BookingStatus::Confirmed && ($this->departure()?->isFuture() ?? false);
    }

    public function refundQuote(): ?RefundQuote
    {
        return $this->canCancel() ? app(RefundService::class)->quote($this->booking) : null;
    }

    public function cancelBooking(RefundService $refunds): void
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $refund = $refunds->cancel($this->booking, $user, RefundReason::PassengerCancelled);
        } catch (BookingRefusedException $e) {
            $this->addError('cancel', $e->getMessage());

            return;
        }

        $this->booking->refresh();
        $this->notice = $refund === null
            ? __('Booking cancelled. No refund is due this close to departure.')
            : __('Booking cancelled. :amount is being refunded to your :method account.', [
                'amount' => Money::format($refund->amount_poisha),
                'method' => $refund->payment->method->getLabel(),
            ]);
    }

    /**
     * Give up an unpaid hold from this page.
     */
    public function releaseHold(SeatInventoryService $inventory): void
    {
        $inventory->release($this->booking);
        $this->booking->refresh();
    }

    public function render(): View
    {
        $this->booking->load(['passengers.seatBooking.seat', 'passengers.concession', 'payments', 'refunds.payment', 'trainRun.train']);

        return view('livewire.booking.booking-details');
    }
}
