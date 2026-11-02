<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\FineReason;
use App\Enums\ScanResult;
use App\Exceptions\BookingRefusedException;
use App\Models\Booking;
use App\Models\Fine;
use App\Models\Passenger;
use App\Models\TrainRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * On-train work for TTEs: the passenger chart, no-shows and fines.
 * Scanning tickets is TicketService::validate().
 */
class CheckingService
{
    /**
     * Everyone holding a ticket on the run, in coach and seat order.
     *
     * @return Collection<int, Passenger>
     */
    public function chart(TrainRun $run): Collection
    {
        return Passenger::query()
            ->whereHas('booking', fn ($q) => $q
                ->where('train_run_id', $run->id)
                ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value]))
            ->with(['booking', 'seatBooking.seat', 'concession'])
            ->get()
            ->sortBy(fn (Passenger $p) => [$p->seatBooking->coach_no, $p->seatBooking->seat->row, $p->seatBooking->seat->col])
            ->values();
    }

    /**
     * Bookings on the run whose ticket a TTE has accepted.
     *
     * @return array<int, true> booking id => true
     */
    public function checkedBookingIds(TrainRun $run): array
    {
        $ids = $run->bookings()
            ->whereHas('ticketScans', fn ($q) => $q->where('result', ScanResult::Valid->value))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * Mark (or unmark) a ticketed passenger who did not board.
     *
     * @throws BookingRefusedException
     */
    public function setNoShow(Passenger $passenger, bool $noShow, User $tte): Passenger
    {
        if (! $passenger->booking->hasTicket()) {
            throw new BookingRefusedException("Booking {$passenger->booking->pnr} is not a valid ticket.");
        }

        $passenger->update([
            'no_show_at' => $noShow ? now() : null,
            'no_show_by' => $noShow ? $tte->id : null,
        ]);

        return $passenger;
    }

    /**
     * Record a cash fine collected on board. Returns it with its receipt number.
     *
     * @param  array{passenger_name: string, reason: string, amount_poisha: int, pnr?: string|null, note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function recordFine(TrainRun $run, array $data, User $tte): Fine
    {
        $reason = FineReason::tryFrom($data['reason']);
        $pnr = strtoupper(trim((string) ($data['pnr'] ?? '')));
        $booking = $pnr === '' ? null : Booking::where('pnr', $pnr)->first();
        $errors = [];

        if (trim($data['passenger_name']) === '') {
            $errors['passenger_name'] = 'Enter the passenger name.';
        }

        if ($reason === null) {
            $errors['reason'] = 'Choose a reason.';
        }

        if ($data['amount_poisha'] <= 0) {
            $errors['amount_poisha'] = 'Enter the amount collected.';
        }

        if ($pnr !== '' && ($booking === null || $booking->train_run_id !== $run->id)) {
            $errors['pnr'] = 'No booking with that PNR on this train.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return Fine::create([
            'receipt_no' => $this->newReceiptNo(),
            'train_run_id' => $run->id,
            'booking_id' => $booking?->id,
            'tte_id' => $tte->id,
            'passenger_name' => trim($data['passenger_name']),
            'reason' => $reason,
            'amount_poisha' => $data['amount_poisha'],
            'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
        ]);
    }

    private function newReceiptNo(): string
    {
        do {
            $no = 'FN'.now()->format('ymd').strtoupper(Str::random(5));
        } while (Fine::where('receipt_no', $no)->exists());

        return $no;
    }
}
