<?php

namespace App\Filament\Pages;

use App\Enums\FineReason;
use App\Enums\Permission;
use App\Enums\RunStatus;
use App\Exceptions\BookingRefusedException;
use App\Models\Fine;
use App\Models\Passenger;
use App\Models\ScheduleStop;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\CheckingService;
use App\Support\Money;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Printable passenger chart for one run: who sits where, who has been checked,
 * no-shows, and fines collected on board.
 *
 * @property-read Collection<int, TrainRun> $runs
 * @property-read array<int, string> $stops
 */
class PassengerChart extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Checking';

    protected static ?string $navigationLabel = 'Passenger chart';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.passenger-chart';

    public ?int $runId = null;

    /** @var array{passenger_name: string, reason: string, amount: string, pnr: string, note: string} */
    public array $fine = ['passenger_name' => '', 'reason' => '', 'amount' => '', 'pnr' => '', 'note' => ''];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::CheckTickets->value) ?? false;
    }

    public function getTitle(): string
    {
        return 'Passenger chart';
    }

    public function mount(): void
    {
        $this->runId = $this->runs->count() === 1 ? $this->runs->first()?->id : null;
    }

    /**
     * Yesterday's (overnight), today's and tomorrow's runs that have not been cancelled.
     *
     * @return Collection<int, TrainRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return TrainRun::query()
            ->whereDate('journey_date', '>=', now()->subDay()->toDateString())
            ->whereDate('journey_date', '<=', now()->addDay()->toDateString())
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
     * @return Collection<int, Passenger>
     */
    public function chart(): Collection
    {
        $run = $this->run();

        return $run === null ? new Collection : app(CheckingService::class)->chart($run);
    }

    /**
     * @return array<int, true>
     */
    public function checked(): array
    {
        $run = $this->run();

        return $run === null ? [] : app(CheckingService::class)->checkedBookingIds($run);
    }

    /**
     * @return Collection<int, Fine>
     */
    public function fines(): Collection
    {
        return $this->runId === null ? new Collection : Fine::where('train_run_id', $this->runId)->with(['tte', 'booking'])->latest('id')->get();
    }

    /**
     * @return array<string, string>
     */
    public function fineReasons(): array
    {
        return collect(FineReason::cases())->mapWithKeys(fn (FineReason $r) => [$r->value => $r->getLabel()])->all();
    }

    public function toggleNoShow(int $passengerId): void
    {
        abort_unless(static::canAccess(), 403);
        $passenger = Passenger::query()->with('booking')->findOrFail($passengerId);
        abort_unless($passenger->booking->train_run_id === $this->runId, 404);

        /** @var User $tte */
        $tte = auth()->user();

        try {
            app(CheckingService::class)->setNoShow($passenger, ! $passenger->isNoShow(), $tte);
        } catch (BookingRefusedException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function recordFine(CheckingService $checking): void
    {
        abort_unless(static::canAccess(), 403);
        $run = $this->run();

        if ($run === null) {
            return;
        }

        $this->validate([
            'fine.passenger_name' => ['required', 'string', 'max:100'],
            'fine.reason' => ['required'],
            'fine.amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'fine.pnr' => ['nullable', 'string', 'max:10'],
            'fine.note' => ['nullable', 'string', 'max:255'],
        ], [], ['fine.passenger_name' => 'name', 'fine.reason' => 'reason', 'fine.amount' => 'amount', 'fine.pnr' => 'PNR']);

        /** @var User $tte */
        $tte = auth()->user();

        try {
            $fine = $checking->recordFine($run, [
                'passenger_name' => $this->fine['passenger_name'],
                'reason' => $this->fine['reason'],
                'amount_poisha' => Money::toPoisha($this->fine['amount']),
                'pnr' => $this->fine['pnr'],
                'note' => $this->fine['note'],
            ], $tte);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('fine.'.($field === 'amount_poisha' ? 'amount' : $field), $messages[0]);
            }

            return;
        }

        $this->fine = ['passenger_name' => '', 'reason' => '', 'amount' => '', 'pnr' => '', 'note' => ''];

        Notification::make()
            ->title("Fine recorded: receipt {$fine->receipt_no}")
            ->body('Collect '.Money::format($fine->amount_poisha).' in cash.')
            ->success()
            ->send();
    }
}
