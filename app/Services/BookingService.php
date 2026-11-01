<?php

namespace App\Services;

use App\Contracts\NidVerifier;
use App\Enums\BookingStatus;
use App\Enums\Channel;
use App\Enums\Gender;
use App\Exceptions\BookingRefusedException;
use App\Exceptions\HoldExpiredException;
use App\Exceptions\SeatUnavailableException;
use App\Models\Booking;
use App\Models\Concession;
use App\Models\Seat;
use App\Models\SeatBooking;
use App\Models\TrainRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Passenger details and fares for a held booking, and the one-step counter sale.
 * Seat inventory itself is always changed through SeatInventoryService.
 */
class BookingService
{
    /** Passengers this old or older must give a valid NID. */
    public const int NID_REQUIRED_FROM_AGE = 18;

    public function __construct(
        private readonly SeatInventoryService $inventory,
        private readonly FareService $fares,
        private readonly NidVerifier $nid,
    ) {}

    /**
     * Save one passenger per seat (in seat order) and recalculate the total.
     *
     * @param  list<array{name: string, age: int|string, gender: string, nid?: string|null, concession_id?: int|string|null}>  $passengers
     *
     * @throws ValidationException with keys like "passengers.0.nid"
     * @throws BookingRefusedException if the booking is no longer on hold
     */
    public function savePassengers(Booking $booking, array $passengers, ?string $contactPhone = null, ?string $contactEmail = null): Booking
    {
        if ($booking->status !== BookingStatus::PendingPayment) {
            throw new BookingRefusedException("Booking {$booking->pnr} is {$booking->status->getLabel()}.");
        }

        if ($booking->channel === Channel::Online && ! $booking->isHeld()) {
            throw new BookingRefusedException('Your seat hold has expired. Please choose your seats again.');
        }

        $seats = $booking->seatBookings()->with('seat')->orderBy('id')->get();

        if (count($passengers) !== $seats->count()) {
            throw ValidationException::withMessages(['passengers' => "Enter details for exactly {$seats->count()} passenger(s), one per seat."]);
        }

        $quote = $this->fares->quote(
            $booking->trainRun,
            $seats->map(fn (SeatBooking $s) => ['coach_no' => $s->coach_no, 'seat_id' => $s->seat_id])->values()->all(),
            $booking->from_seq,
            $booking->to_seq,
        );

        $rows = [];
        $errors = [];

        foreach ($seats->values() as $i => $seat) {
            $p = $passengers[$i];
            $age = (int) $p['age'];
            $gender = Gender::tryFrom((string) $p['gender']);
            $nid = trim((string) ($p['nid'] ?? '')) ?: null;
            $concession = filled($p['concession_id'] ?? null) ? Concession::find($p['concession_id']) : null;

            if (trim($p['name']) === '') {
                $errors["passengers.{$i}.name"] = 'Enter the passenger name.';
            }

            if ($age < 0 || $age > 120) {
                $errors["passengers.{$i}.age"] = 'Enter a valid age.';
            }

            if ($gender === null) {
                $errors["passengers.{$i}.gender"] = 'Choose a gender.';
            } elseif ($seat->seat->hasFlag(Seat::FLAG_WOMEN) && $gender !== Gender::Female) {
                $errors["passengers.{$i}.gender"] = "Seat {$seat->coach_no}-{$seat->seat->seat_no} is reserved for women.";
            }

            if ($age >= self::NID_REQUIRED_FROM_AGE && $nid === null) {
                $errors["passengers.{$i}.nid"] = 'NID is required for passengers aged '.self::NID_REQUIRED_FROM_AGE.' or over.';
            } elseif ($nid !== null && ! $this->nid->verify($nid)) {
                $errors["passengers.{$i}.nid"] = 'NID must be 10, 13 or 17 digits.';
            }

            if (filled($p['concession_id'] ?? null) && ($concession === null || ! $concession->is_active)) {
                $errors["passengers.{$i}.concession_id"] = 'This concession is not available.';
            } elseif ($concession !== null && ! $concession->allowsAge($age)) {
                $errors["passengers.{$i}.concession_id"] = "{$concession->name} concession does not apply at age {$age}.";
            }

            $rows[] = [
                'seat_booking_id' => $seat->id,
                'name' => trim($p['name']),
                'age' => $age,
                'gender' => $gender,
                'nid' => $nid === null ? null : (preg_replace('/[\s-]/', '', $nid) ?? $nid),
                'concession_id' => $concession?->id,
                'fare_poisha' => $this->fares->applyConcession($quote->forSeat($seat->coach_no, $seat->seat_id), $concession),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($booking, $rows, $contactPhone, $contactEmail) {
            $booking->passengers()->delete();
            $booking->passengers()->createMany($rows);

            $booking->update([
                'total_poisha' => array_sum(array_column($rows, 'fare_poisha')),
                'contact_phone' => $contactPhone,
                'contact_email' => $contactEmail,
            ]);

            return $booking->refresh();
        });
    }

    /**
     * Counter sale in one step: hold, passengers and confirm (cash taken), all or nothing.
     *
     * @param  array<int, array{coach_no: string, seat_id: int|string}>  $seats
     * @param  list<array{name: string, age: int|string, gender: string, nid?: string|null, concession_id?: int|string|null}>  $passengers
     *
     * @throws SeatUnavailableException|BookingRefusedException|ValidationException|HoldExpiredException
     */
    public function counterSale(TrainRun $run, array $seats, int $fromSeq, int $toSeq, array $passengers, User $clerk, ?string $contactPhone = null): Booking
    {
        return DB::transaction(function () use ($run, $seats, $fromSeq, $toSeq, $passengers, $clerk, $contactPhone) {
            $booking = $this->inventory->hold($run, $seats, $fromSeq, $toSeq, Channel::Counter, soldBy: $clerk);
            $this->savePassengers($booking, $passengers, $contactPhone);

            return $this->inventory->confirm($booking);
        });
    }
}
