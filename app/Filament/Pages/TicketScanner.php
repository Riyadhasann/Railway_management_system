<?php

namespace App\Filament\Pages;

use App\Data\ScanOutcome;
use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Enums\RunStatus;
use App\Enums\ScanResult;
use App\Models\Booking;
use App\Models\ScheduleStop;
use App\Models\TicketScan;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\TicketService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * TTE ticket checking on a phone: pick the run you are on, scan QR codes with the camera
 * (html5-qrcode), see the result at a glance. Every scan is recorded by TicketService.
 *
 * @property-read Collection<int, TrainRun> $runs
 */
class TicketScanner extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Checking';

    protected static ?string $navigationLabel = 'Scan tickets';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.ticket-scanner';

    public ?int $runId = null;

    /** Typed or pasted code, when the camera can't be used. */
    public string $code = '';

    /** @var array{result: string, label: string, message: string, pnr: ?string, route: ?string, passengers: list<string>, scanned_at: string}|null */
    public ?array $last = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::CheckTickets->value) ?? false;
    }

    public function getTitle(): string
    {
        return 'Scan tickets';
    }

    public function mount(): void
    {
        $this->runId = $this->runs->count() === 1 ? $this->runs->first()?->id : null;
    }

    /**
     * Runs a TTE can be on now: today's, plus yesterday's overnight trains.
     *
     * @return Collection<int, TrainRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return TrainRun::query()
            ->whereDate('journey_date', '>=', now()->subDay()->toDateString())
            ->whereDate('journey_date', '<=', now()->toDateString())
            ->whereIn('status', [RunStatus::Scheduled->value, RunStatus::Running->value])
            ->with('train')
            ->orderBy('journey_date')
            ->get()
            ->sortBy(fn (TrainRun $r) => [$r->journey_date->toDateString(), $r->train->number])
            ->values();
    }

    public function run(): ?TrainRun
    {
        return $this->runId === null ? null : $this->runs->firstWhere('id', $this->runId);
    }

    /**
     * Called by the camera with the decoded QR text, or by the manual form.
     */
    public function scan(?string $token = null): void
    {
        abort_unless(static::canAccess(), 403);
        $run = $this->run();
        $token = trim($token ?? $this->code);

        if ($run === null || $token === '') {
            return;
        }

        /** @var User $tte */
        $tte = auth()->user();
        $this->last = $this->present(app(TicketService::class)->validate($token, $run, $tte));
        $this->code = '';
    }

    public function clear(): void
    {
        $this->last = null;
    }

    /**
     * Scans recorded on this run by anyone, newest first.
     *
     * @return Collection<int, TicketScan>
     */
    public function recentScans(): Collection
    {
        return $this->runId === null ? new Collection : TicketScan::query()
            ->where('train_run_id', $this->runId)
            ->with('tte')
            ->latest('id')
            ->limit(8)
            ->get();
    }

    /**
     * @return array{valid: int, total: int}
     */
    public function counts(): array
    {
        $run = $this->run();

        if ($run === null) {
            return ['valid' => 0, 'total' => 0];
        }

        return [
            'valid' => TicketScan::where('train_run_id', $run->id)->where('result', ScanResult::Valid->value)->count(),
            'total' => Booking::where('train_run_id', $run->id)
                ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])
                ->count(),
        ];
    }

    /**
     * @return array{result: string, label: string, message: string, pnr: ?string, route: ?string, passengers: list<string>, scanned_at: string}
     */
    private function present(ScanOutcome $outcome): array
    {
        $booking = $outcome->booking?->loadMissing(['passengers.seatBooking.seat', 'trainRun.train.scheduleStops.routeStop.station']);
        $route = null;

        if ($booking !== null) {
            $names = $booking->trainRun->train->scheduleStops
                ->mapWithKeys(fn (ScheduleStop $s) => [$s->routeStop->sequence => $s->routeStop->station->name]);
            $route = ($names[$booking->from_seq] ?? '?').' → '.($names[$booking->to_seq] ?? '?');
        }

        return [
            'result' => $outcome->result->value,
            'label' => $outcome->result->getLabel(),
            'message' => $outcome->message,
            'pnr' => $outcome->scan->pnr,
            'route' => $route,
            'passengers' => $booking === null ? [] : array_values($booking->passengers->sortBy('id')->map(
                fn ($p) => "{$p->seatBooking->coach_no}-{$p->seatBooking->seat->seat_no} · {$p->name} ({$p->age}, {$p->gender->getLabel()})"
            )->all()),
            'scanned_at' => $outcome->scan->scanned_at->format('H:i:s'),
        ];
    }
}
