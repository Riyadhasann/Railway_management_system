<?php

namespace App\Filament\Pages;

use App\Data\CoachMap;
use App\Enums\Channel;
use App\Enums\Gender;
use App\Enums\Permission;
use App\Enums\RunStatus;
use App\Enums\SeatState;
use App\Exceptions\BookingRefusedException;
use App\Exceptions\FareNotFoundException;
use App\Exceptions\HoldExpiredException;
use App\Exceptions\SeatUnavailableException;
use App\Models\Booking;
use App\Models\Concession;
use App\Models\ScheduleStop;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FareService;
use App\Services\SeatInventoryService;
use App\Support\Money;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Counter clerk sells seats for cash in one step (hold + passengers + confirm).
 * Counter-quota seats are on sale here; the same SeatInventoryService guards every seat.
 *
 * @property-read Collection<int, TrainRun> $runs
 * @property-read array<int, string> $stops
 * @property-read list<CoachMap> $coaches
 * @property-read Collection<int, Concession> $concessions
 */
class CounterBooking extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    protected static ?string $navigationLabel = 'Counter booking';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.counter-booking';

    public string $date = '';

    public ?int $runId = null;

    public int $fromSeq = 0;

    public int $toSeq = 0;

    /** @var list<string> "coachNo|seatId" */
    public array $selected = [];

    /** @var array<string, array{name: string, age: int|string|null, gender: string, nid: string, concession_id: int|string|null}> keyed like */
    public array $passengers = [];

    public string $phone = '';

    public ?string $lastPnr = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::CounterBooking->value) ?? false;
    }

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function getTitle(): string
    {
        return 'Counter booking';
    }

    /**
     * @return Collection<int, TrainRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return TrainRun::query()
            ->whereDate('journey_date', $this->date)
            ->where('status', RunStatus::Scheduled->value)
            ->with('train')
            ->get()
            ->sortBy(fn (TrainRun $r) => $r->train->number)
            ->values();
    }

    public function run(): ?TrainRun
    {
        return $this->runId ? $this->runs->firstWhere('id', $this->runId) : null;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function stops(): array
    {
        return $this->run()?->train->scheduleStops()->with('routeStop.station')->get()
            ->mapWithKeys(fn (ScheduleStop $s) => [$s->routeStop->sequence => $s->routeStop->station->name])
            ->all() ?? [];
    }

    /**
     * @return list<CoachMap>
     */
    #[Computed]
    public function coaches(): array
    {
        $run = $this->run();

        if ($run === null || $this->fromSeq >= $this->toSeq) {
            return [];
        }

        return app(SeatInventoryService::class)->availability($run, $this->fromSeq, $this->toSeq, Channel::Counter);
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
     * @return array<string, string>
     */
    public function genders(): array
    {
        return collect(Gender::cases())->mapWithKeys(fn (Gender $g) => [$g->value => $g->getLabel()])->all();
    }

    public function updatedDate(): void
    {
        $this->runId = null;
        $this->resetJourney();
    }

    public function updatedRunId(): void
    {
        $this->resetJourney();
        $run = $this->run();

        if ($run !== null) {
            [$this->fromSeq, $this->toSeq] = app(SeatInventoryService::class)->fullJourney($run);
        }
    }

    public function updatedFromSeq(): void
    {
        $this->clearSelection();
    }

    public function updatedToSeq(): void
    {
        $this->clearSelection();
    }

    public function refreshMap(): void
    {
        unset($this->coaches);
        foreach ($this->selected as $key) {
            if (! $this->isAvailable($key)) {
                $this->toggleSeat(...$this->splitKey($key));
            }
        }
    }

    public function toggleSeat(string $coachNo, int $seatId): void
    {
        $key = "{$coachNo}|{$seatId}";

        if (in_array($key, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$key]));
            unset($this->passengers[$key]);

            return;
        }

        if (! $this->isAvailable($key) || count($this->selected) >= (int) config('railway.max_passengers')) {
            return;
        }

        $this->selected[] = $key;
        $this->passengers[$key] = ['name' => '', 'age' => null, 'gender' => '', 'nid' => '', 'concession_id' => null];
    }

    public function baseTotal(): ?int
    {
        $run = $this->run();

        if ($run === null || $this->selected === []) {
            return 0;
        }

        try {
            return app(FareService::class)->quote($run, $this->seatList(), $this->fromSeq, $this->toSeq)->total();
        } catch (FareNotFoundException) {
            return null;
        }
    }

    public function sell(): void
    {
        abort_unless(static::canAccess(), 403);
        $run = $this->run();

        if ($run === null || $this->selected === []) {
            Notification::make()->title('Choose a train and at least one seat.')->warning()->send();

            return;
        }

        $this->validate([
            'passengers.*.name' => ['required', 'string', 'max:100'],
            'passengers.*.age' => ['required', 'integer', 'between:0,120'],
            'passengers.*.gender' => ['required'],
            'phone' => ['nullable', 'regex:/^01[3-9]\d{8}$/'],
        ], [], ['passengers.*.name' => 'name', 'passengers.*.age' => 'age', 'passengers.*.gender' => 'gender']);

        /** @var User $clerk */
        $clerk = auth()->user();

        try {
            $booking = app(BookingService::class)->counterSale(
                $run,
                $this->seatList(),
                $this->fromSeq,
                $this->toSeq,
                // Same order as the seats, so passenger i sits in seat i.
                array_map(fn (string $key) => [
                    'name' => (string) $this->passengers[$key]['name'],
                    'age' => (int) $this->passengers[$key]['age'],
                    'gender' => (string) $this->passengers[$key]['gender'],
                    'nid' => (string) $this->passengers[$key]['nid'],
                    'concession_id' => $this->passengers[$key]['concession_id'] ?: null,
                ], $this->selected),
                $clerk,
                $this->phone !== '' ? $this->phone : null,
            );
        } catch (ValidationException $e) {
            // Service errors use list positions ("passengers.0.nid"); the form is keyed by seat.
            foreach ($e->errors() as $field => $messages) {
                if (preg_match('/^passengers\.(\d+)\.(\w+)$/', $field, $m) && isset($this->selected[(int) $m[1]])) {
                    $field = "passengers.{$this->selected[(int) $m[1]]}.{$m[2]}";
                }
                $this->addError($field, $messages[0]);
            }

            return;
        } catch (SeatUnavailableException|BookingRefusedException|HoldExpiredException|FareNotFoundException|InvalidArgumentException $e) {
            $this->refreshMap();
            Notification::make()->title('Not sold')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->lastPnr = $booking->pnr;
        $this->phone = '';
        $this->clearSelection();

        Notification::make()
            ->title("Sold: PNR {$booking->pnr}")
            ->body('Collect '.Money::format($booking->total_poisha).' in cash.')
            ->success()
            ->persistent()
            ->send();
    }

    public function lastBooking(): ?Booking
    {
        return $this->lastPnr ? Booking::where('pnr', $this->lastPnr)->with('passengers.seatBooking.seat')->first() : null;
    }

    public function seatLabel(string $key): string
    {
        [$coachNo, $seatId] = $this->splitKey($key);

        foreach ($this->coaches as $coach) {
            if ($coach->coachNo === $coachNo) {
                foreach ($coach->seats as $seat) {
                    if ($seat->seatId === $seatId) {
                        return "{$coachNo}-{$seat->seatNo}";
                    }
                }
            }
        }

        return $coachNo;
    }

    private function resetJourney(): void
    {
        $this->fromSeq = 0;
        $this->toSeq = 0;
        $this->clearSelection();
    }

    private function clearSelection(): void
    {
        $this->selected = [];
        $this->passengers = [];
        $this->resetErrorBag();
        unset($this->coaches);
    }

    /**
     * @return list<array{coach_no: string, seat_id: int}>
     */
    private function seatList(): array
    {
        return array_map(function (string $key) {
            [$coachNo, $seatId] = $this->splitKey($key);

            return ['coach_no' => $coachNo, 'seat_id' => $seatId];
        }, $this->selected);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function splitKey(string $key): array
    {
        [$coachNo, $seatId] = explode('|', $key, 2);

        return [$coachNo, (int) $seatId];
    }

    private function isAvailable(string $key): bool
    {
        [$coachNo, $seatId] = $this->splitKey($key);

        foreach ($this->coaches as $coach) {
            if ($coach->coachNo === $coachNo) {
                foreach ($coach->seats as $seat) {
                    if ($seat->seatId === $seatId) {
                        return $seat->state === SeatState::Available;
                    }
                }
            }
        }

        return false;
    }
}
