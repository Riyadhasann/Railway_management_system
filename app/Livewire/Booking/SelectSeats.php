<?php

namespace App\Livewire\Booking;

use App\Data\CoachMap;
use App\Enums\Channel;
use App\Enums\SeatState;
use App\Exceptions\BookingRefusedException;
use App\Exceptions\FareNotFoundException;
use App\Exceptions\SeatUnavailableException;
use App\Models\ScheduleStop;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\FareService;
use App\Services\SeatInventoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Passenger portal step 2: live seat map for one run and segment; pick up to 4 seats and hold them.
 *
 * @property-read list<CoachMap> $coaches
 * @property-read array<int, string> $stops
 */
#[Title('Choose seats')]
class SelectSeats extends Component
{
    /** Hold attempts per user per minute. */
    public const int HOLDS_PER_MINUTE = 10;

    #[Locked]
    public TrainRun $run;

    #[Locked]
    public int $from;

    #[Locked]
    public int $to;

    /** @var list<string> "coachNo|seatId" */
    public array $selected = [];

    public ?string $notice = null;

    public function mount(TrainRun $run, int $from, int $to): void
    {
        $stops = $run->train->scheduleStops()->with('routeStop')->get()
            ->map(fn (ScheduleStop $s) => $s->routeStop->sequence);

        abort_unless($from < $to && $stops->contains($from) && $stops->contains($to), 404);

        $this->run = $run;
        $this->from = $from;
        $this->to = $to;
    }

    /**
     * @return list<CoachMap>
     */
    #[Computed]
    public function coaches(): array
    {
        return app(SeatInventoryService::class)->availability($this->run, $this->from, $this->to, Channel::Online);
    }

    /**
     * @return array<int, string> sequence => station name
     */
    #[Computed]
    public function stops(): array
    {
        return $this->run->train->scheduleStops()->with('routeStop.station')->get()
            ->mapWithKeys(fn (ScheduleStop $s) => [$s->routeStop->sequence => $s->routeStop->station->name])
            ->all();
    }

    public function maxSeats(): int
    {
        return (int) config('railway.max_passengers');
    }

    /**
     * Called by wire:poll: refresh the map and drop picks someone else has taken meanwhile.
     */
    public function refreshMap(): void
    {
        unset($this->coaches);
        $before = count($this->selected);
        $this->selected = array_values(array_filter($this->selected, fn (string $key) => $this->isAvailable($key)));

        if (count($this->selected) < $before) {
            $this->notice = __('A seat you picked has just been taken by someone else.');
        }
    }

    public function toggleSeat(string $coachNo, int $seatId): void
    {
        $key = "{$coachNo}|{$seatId}";
        $this->notice = null;

        if (in_array($key, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$key]));

            return;
        }

        if (! $this->isAvailable($key)) {
            return;
        }

        if (count($this->selected) >= $this->maxSeats()) {
            $this->notice = __('You can book at most :max seats at a time.', ['max' => $this->maxSeats()]);

            return;
        }

        $this->selected[] = $key;
    }

    /**
     * Fare for the current selection in poisha, or null when a class has no fare rule.
     */
    public function selectionTotal(): ?int
    {
        if ($this->selected === []) {
            return 0;
        }

        try {
            return app(FareService::class)->quote($this->run, $this->seatList(), $this->from, $this->to)->total();
        } catch (FareNotFoundException) {
            return null;
        }
    }

    public function hold(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $limiterKey = "seat-hold:{$user->id}";

        if (RateLimiter::tooManyAttempts($limiterKey, self::HOLDS_PER_MINUTE)) {
            $this->notice = __('Too many attempts. Please wait a minute and try again.');

            return;
        }
        RateLimiter::hit($limiterKey, 60);

        try {
            $booking = app(SeatInventoryService::class)->hold($this->run, $this->seatList(), $this->from, $this->to, Channel::Online, $user);
        } catch (SeatUnavailableException $e) {
            $this->refreshMap();
            $this->notice = $e->getMessage().' '.__('Please choose other seats.');

            return;
        } catch (BookingRefusedException|FareNotFoundException $e) {
            $this->notice = $e->getMessage();

            return;
        }

        $this->redirectRoute('booking.checkout', ['booking' => $booking->pnr], navigate: true);
    }

    /**
     * @return list<array{coach_no: string, seat_id: int}>
     */
    private function seatList(): array
    {
        return array_map(function (string $key) {
            [$coachNo, $seatId] = explode('|', $key, 2);

            return ['coach_no' => $coachNo, 'seat_id' => (int) $seatId];
        }, $this->selected);
    }

    private function isAvailable(string $key): bool
    {
        foreach ($this->coaches as $coach) {
            foreach ($coach->seats as $seat) {
                if ("{$coach->coachNo}|{$seat->seatId}" === $key) {
                    return $seat->state === SeatState::Available;
                }
            }
        }

        return false;
    }

    public function render(): View
    {
        return view('livewire.booking.select-seats');
    }
}
