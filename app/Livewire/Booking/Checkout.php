<?php

namespace App\Livewire\Booking;

use App\Enums\BookingStatus;
use App\Enums\Gender;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingRefusedException;
use App\Models\Booking;
use App\Models\Concession;
use App\Models\Passenger;
use App\Models\ScheduleStop;
use App\Models\SeatBooking;
use App\Services\BookingService;
use App\Services\OtpService;
use App\Services\PaymentService;
use App\Services\SeatInventoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Passenger portal step 3: passenger details and phone check while the hold counts down,
 * then off to the payment gateway.
 *
 * @property-read Collection<int, SeatBooking> $seats
 * @property-read Collection<int, Concession> $concessions
 * @property-read array<int, string> $stops
 */
#[Title('Checkout')]
class Checkout extends Component
{
    /** Bangladeshi mobile number: 01 + operator digit 3-9 + 8 digits. */
    public const string PHONE_PATTERN = '/^01[3-9]\d{8}$/';

    #[Locked]
    public Booking $booking;

    /** @var list<array{name: string, age: int|string|null, gender: string, nid: string, concession_id: int|string|null}> */
    public array $passengers = [];

    public string $phone = '';

    public string $otp = '';

    /** Prototype only: the code is shown on screen instead of arriving by SMS. */
    #[Locked]
    public ?string $otpShown = null;

    #[Locked]
    public ?string $verifiedPhone = null;

    public string $method = 'bkash';

    public function mount(Booking $booking): void
    {
        abort_unless($booking->user_id === auth()->id(), 403);

        if ($booking->hasTicket()) {
            $this->redirectRoute('booking.show', ['booking' => $booking->pnr], navigate: true);
        }

        $this->booking = $booking;
        $saved = $booking->passengers()->get()->keyBy('seat_booking_id');

        foreach ($this->seats as $seat) {
            /** @var Passenger|null $p */
            $p = $saved->get($seat->id);
            $this->passengers[] = [
                'name' => $p->name ?? '',
                'age' => $p?->age,
                'gender' => $p?->gender->value ?? '',
                'nid' => $p->nid ?? '',
                'concession_id' => $p?->concession_id,
            ];
        }

        $this->phone = $booking->contact_phone ?? '';
        $this->verifiedPhone = $booking->contact_phone;
    }

    /**
     * @return Collection<int, SeatBooking>
     */
    #[Computed]
    public function seats(): Collection
    {
        return $this->booking->seatBookings()->with('seat')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, Concession>
     */
    #[Computed]
    public function concessions(): Collection
    {
        return Concession::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function stops(): array
    {
        return $this->booking->trainRun->train->scheduleStops()->with('routeStop.station')->get()
            ->mapWithKeys(fn (ScheduleStop $s) => [$s->routeStop->sequence => $s->routeStop->station->name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function genders(): array
    {
        return collect(Gender::cases())->mapWithKeys(fn (Gender $g) => [$g->value => $g->getLabel()])->all();
    }

    public function isEditable(): bool
    {
        return $this->booking->isHeld();
    }

    public function hasPassengers(): bool
    {
        return $this->booking->passengers()->exists();
    }

    public function sendOtp(OtpService $otp): void
    {
        $this->validate(['phone' => ['required', 'regex:'.self::PHONE_PATTERN]], ['phone.regex' => __('Enter a mobile number like 01712345678.')]);

        $this->otpShown = $otp->send("booking:{$this->booking->id}", $this->phone);
        $this->verifiedPhone = null;
        $this->otp = '';
    }

    public function verifyOtp(OtpService $otp): void
    {
        $this->validate(['otp' => ['required', 'digits:6']]);

        if (! $otp->verify("booking:{$this->booking->id}", $this->phone, $this->otp)) {
            $this->addError('otp', __('That code is not right, or it has expired.'));

            return;
        }

        $this->verifiedPhone = $this->phone;
        $this->otpShown = null;
    }

    public function save(BookingService $bookings): void
    {
        if ($this->verifiedPhone === null || $this->verifiedPhone !== $this->phone) {
            $this->addError('phone', __('Confirm your mobile number with the code first.'));

            return;
        }

        $this->validate([
            'passengers.*.name' => ['required', 'string', 'max:100'],
            'passengers.*.age' => ['required', 'integer', 'between:0,120'],
            'passengers.*.gender' => ['required'],
            'passengers.*.nid' => ['nullable', 'string', 'max:25'],
        ], [], [
            'passengers.*.name' => 'name',
            'passengers.*.age' => 'age',
            'passengers.*.gender' => 'gender',
        ]);

        try {
            $this->booking = $bookings->savePassengers(
                $this->booking,
                array_map(fn (array $p) => [
                    'name' => (string) $p['name'],
                    'age' => (int) $p['age'],
                    'gender' => (string) $p['gender'],
                    'nid' => (string) $p['nid'],
                    'concession_id' => $p['concession_id'] ?: null,
                ], $this->passengers),
                $this->verifiedPhone,
                auth()->user()?->email,
            );
        } catch (BookingRefusedException $e) {
            $this->addError('booking', $e->getMessage());
            $this->booking->refresh();

            return;
        }

        $this->dispatch('passengers-saved');
    }

    /**
     * @return array<string, string>
     */
    public function methods(): array
    {
        return collect(PaymentMethod::online())->mapWithKeys(fn (PaymentMethod $m) => [$m->value => $m->getLabel()])->all();
    }

    /**
     * Send the passenger to the payment gateway.
     */
    public function pay(PaymentService $payments): void
    {
        $method = PaymentMethod::tryFrom($this->method);

        if ($method === null || ! $method->isOnline()) {
            $this->addError('method', __('Choose how to pay.'));

            return;
        }

        try {
            $url = $payments->start($this->booking, $method);
        } catch (BookingRefusedException $e) {
            $this->addError('booking', $e->getMessage());
            $this->booking->refresh();

            return;
        }

        $this->redirect($url);
    }

    public function cancel(SeatInventoryService $inventory): void
    {
        $inventory->release($this->booking);

        $this->redirectRoute('booking.search', navigate: true);
    }

    /**
     * Re-read the booking when the countdown reaches zero.
     */
    public function expire(): void
    {
        $this->booking->refresh();
    }

    public function statusMessage(): ?string
    {
        return match (true) {
            $this->booking->status === BookingStatus::Confirmed => 'This booking is confirmed.',
            $this->booking->status === BookingStatus::Cancelled => 'This booking was cancelled.',
            $this->booking->status === BookingStatus::PendingPayment && ! $this->booking->isHeld(),
            $this->booking->status === BookingStatus::Expired => 'Your seat hold has expired and the seats were released. Please search again.',
            $this->booking->status === BookingStatus::Failed => 'This booking failed.',
            default => null,
        };
    }

    public function render(): View
    {
        return view('livewire.booking.checkout');
    }
}
