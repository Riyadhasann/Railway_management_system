<?php

namespace App\Filament\Resources\TrainRuns\Pages;

use App\Data\CoachMap;
use App\Enums\SeatState;
use App\Exceptions\SeatUnavailableException;
use App\Filament\Resources\TrainRuns\TrainRunResource;
use App\Models\ScheduleStop;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\SeatInventoryService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * Seat map of one train run for a chosen journey segment, with admin seat blocking.
 *
 * @property-read list<CoachMap> $coaches Livewire computed property, see coaches()
 * @property-read array<int, string> $stops Livewire computed property, see stops()
 */
class ViewSeatMap extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TrainRunResource::class;

    protected string $view = 'filament.train-runs.view-seat-map';

    public int $fromSeq = 0;

    public int $toSeq = 0;

    /** @var list<string> "coachNo|seatId" */
    public array $selected = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();

        [$this->fromSeq, $this->toSeq] = app(SeatInventoryService::class)->fullJourney($this->run());
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(TrainRunResource::canView($this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        $run = $this->run();

        return "Seat map: {$run->train->label()} on {$run->journey_date->format('D, d M Y')}";
    }

    public function run(): TrainRun
    {
        /** @var TrainRun */
        return $this->getRecord();
    }

    public function canBlock(): bool
    {
        return Gate::allows('update', $this->run()) && ! $this->run()->isCancelled();
    }

    /**
     * Stops the train calls at, as sequence => station name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function stops(): array
    {
        return $this->run()->train->scheduleStops()->with('routeStop.station')->get()
            ->mapWithKeys(fn (ScheduleStop $s) => [$s->routeStop->sequence => $s->routeStop->station->name])
            ->all();
    }

    /**
     * @return list<CoachMap>
     */
    #[Computed]
    public function coaches(): array
    {
        if ($this->fromSeq >= $this->toSeq) {
            return [];
        }

        return app(SeatInventoryService::class)->availability($this->run(), $this->fromSeq, $this->toSeq);
    }

    public function updatedFromSeq(): void
    {
        $this->selected = [];
    }

    public function updatedToSeq(): void
    {
        $this->selected = [];
    }

    public function toggleSeat(string $coachNo, int $seatId): void
    {
        if (! $this->canBlock()) {
            return;
        }

        $key = "{$coachNo}|{$seatId}";
        $this->selected = in_array($key, $this->selected, true)
            ? array_values(array_diff($this->selected, [$key]))
            : [...$this->selected, $key];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('block')
                ->label(fn () => 'Block selected ('.count($this->selected).')')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(fn () => $this->canBlock())
                ->disabled(fn () => $this->selected === [])
                ->modalDescription('Blocked seats cannot be sold online or at the counter for the selected journey.')
                ->schema([
                    TextInput::make('reason')->required()->maxLength(255)->placeholder('e.g. VIP quota, damaged seat'),
                ])
                ->action(fn (array $data) => $this->blockSelected($data['reason'])),

            Action::make('unblock')
                ->label('Unblock selected')
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('gray')
                ->visible(fn () => $this->canBlock())
                ->disabled(fn () => $this->selected === [])
                ->requiresConfirmation()
                ->action(fn () => $this->unblockSelected()),

            Action::make('back')
                ->label('All runs')
                ->color('gray')
                ->url(TrainRunResource::getUrl('index')),
        ];
    }

    public function blockSelected(string $reason): void
    {
        abort_unless($this->canBlock(), 403);
        /** @var User $user */
        $user = auth()->user();
        $total = 0;

        try {
            foreach ($this->selectedByCoach() as $coachNo => $seatIds) {
                $total += app(SeatInventoryService::class)
                    ->block($this->run(), $coachNo, $seatIds, $this->fromSeq, $this->toSeq, $user, $reason);
            }
        } catch (SeatUnavailableException|InvalidArgumentException $e) {
            Notification::make()->title('Some seats were not blocked')->body($e->getMessage())->danger()->send();
        }

        $this->afterChange($total > 0 ? "Blocked {$total} seat(s)" : null);
    }

    public function unblockSelected(): void
    {
        abort_unless($this->canBlock(), 403);
        $total = 0;

        foreach ($this->selectedByCoach() as $coachNo => $seatIds) {
            $total += app(SeatInventoryService::class)->unblock($this->run(), $coachNo, $seatIds);
        }

        $this->afterChange($total > 0 ? "Unblocked {$total} seat(s)" : 'None of the selected seats were blocked');
    }

    /**
     * @return array<string, list<int>>
     */
    private function selectedByCoach(): array
    {
        $byCoach = [];
        foreach ($this->selected as $key) {
            [$coachNo, $seatId] = explode('|', $key, 2);
            $byCoach[$coachNo][] = (int) $seatId;
        }

        return $byCoach;
    }

    private function afterChange(?string $message): void
    {
        $this->selected = [];
        unset($this->coaches);

        if ($message !== null) {
            Notification::make()->title($message)->success()->send();
        }
    }

    /**
     * @return array<string, int>
     */
    public function totals(): array
    {
        $totals = array_fill_keys(array_map(fn (SeatState $s) => $s->value, SeatState::cases()), 0);

        foreach ($this->coaches as $coach) {
            foreach (SeatState::cases() as $state) {
                $totals[$state->value] += $coach->count($state);
            }
        }

        return $totals;
    }
}
