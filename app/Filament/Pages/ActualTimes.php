<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\RunStatus;
use App\Models\RunStopTime;
use App\Models\ScheduleStop;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\TrainRunService;
use BackedEnum;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Station master types the actual arrival and departure at each stop.
 * The run's delay is worked out from them and shown to passengers.
 *
 * @property-read Collection<int, TrainRun> $runs
 * @property-read Collection<int, ScheduleStop> $stops
 */
class ActualTimes extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Actual times';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.actual-times';

    public ?int $runId = null;

    /** @var array<int, array{arrival: string, departure: string}> keyed by schedule stop id */
    public array $times = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::RecordActualTimes->value) ?? false;
    }

    public function getTitle(): string
    {
        return 'Actual times';
    }

    /**
     * Yesterday's and today's runs that have not been cancelled.
     *
     * @return Collection<int, TrainRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return TrainRun::query()
            ->whereDate('journey_date', '>=', now()->subDay()->toDateString())
            ->whereDate('journey_date', '<=', now()->toDateString())
            ->where('status', '!=', RunStatus::Cancelled->value)
            ->with('train')
            ->get()
            ->sortBy(fn (TrainRun $r) => [$r->journey_date->toDateString(), $r->train->number])
            ->values();
    }

    public function run(): ?TrainRun
    {
        return $this->runId === null ? null : $this->runs->firstWhere('id', $this->runId);
    }

    /**
     * @return Collection<int, ScheduleStop>
     */
    #[Computed]
    public function stops(): Collection
    {
        return $this->run()?->train->scheduleStops()->with('routeStop.station')->get()
            ->sortBy(fn (ScheduleStop $s) => $s->routeStop->sequence)
            ->values() ?? new Collection;
    }

    /**
     * @return Collection<int, RunStopTime>
     */
    public function recorded(): Collection
    {
        return $this->runId === null ? new Collection : RunStopTime::where('train_run_id', $this->runId)->with('recorder')->get()->keyBy('schedule_stop_id');
    }

    public function updatedRunId(): void
    {
        unset($this->stops);
        $recorded = $this->recorded();
        $this->times = [];

        foreach ($this->stops as $stop) {
            $time = $recorded->get($stop->id);
            $this->times[$stop->id] = [
                'arrival' => $time?->actual_arrival?->format('H:i') ?? '',
                'departure' => $time?->actual_departure?->format('H:i') ?? '',
            ];
        }
    }

    public function save(int $stopId, TrainRunService $runs): void
    {
        abort_unless(static::canAccess(), 403);
        $run = $this->run();
        $stop = $this->stops->firstWhere('id', $stopId);

        if ($run === null || $stop === null) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $this->resetErrorBag("times.{$stopId}");

        try {
            $time = $runs->recordActualTime($run, $stop, $this->times[$stopId]['arrival'] ?? null, $this->times[$stopId]['departure'] ?? null, $user);
        } catch (DomainException $e) {
            $this->addError("times.{$stopId}", $e->getMessage());

            return;
        }

        unset($this->runs);
        $run = $this->run();

        Notification::make()
            ->title("Saved for {$stop->routeStop->station->name}")
            ->body($time->delay_minutes > 0 ? "{$time->delay_minutes} min late here. Train now: ".($run?->progressLabel() ?? '') : 'On time here.')
            ->success()
            ->send();
    }
}
