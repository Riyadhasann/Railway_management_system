<?php

namespace App\Filament\Pages;

use App\Enums\LocoStatus;
use App\Enums\Permission;
use App\Enums\RunStatus;
use App\Enums\StaffDesignation;
use App\Models\CrewAssignment;
use App\Models\Locomotive;
use App\Models\Staff;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\CrewService;
use BackedEnum;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Crew controller staffs each train run with crew and a locomotive. CrewService enforces
 * the rules (valid licence, loco not under repair, one train per day); this page shows
 * what each run still needs and which licences and maintenance dates are coming up.
 *
 * @property-read Collection<int, TrainRun> $runs
 */
class CrewAssignments extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Crew and locos';

    protected static ?string $navigationLabel = 'Assignments';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.crew-assignments';

    public string $date = '';

    public ?int $runId = null;

    public ?int $staffId = null;

    public ?int $locoId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can(Permission::ViewAssignments->value) || $user->can(Permission::ManageAssignments->value));
    }

    public static function canManage(): bool
    {
        return auth()->user()?->can(Permission::ManageAssignments->value) ?? false;
    }

    public function getTitle(): string
    {
        return 'Crew and loco assignments';
    }

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    /**
     * @return Collection<int, TrainRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return TrainRun::query()
            ->whereDate('journey_date', $this->date)
            ->where('status', '!=', RunStatus::Cancelled->value)
            ->with(['train', 'crewAssignments', 'locoAssignment.locomotive'])
            ->get()
            ->sortBy(fn (TrainRun $r) => $r->train->number)
            ->values();
    }

    public function run(): ?TrainRun
    {
        return $this->runId === null ? null : $this->runs->firstWhere('id', $this->runId);
    }

    /**
     * @return list<string>
     */
    public function problems(TrainRun $run): array
    {
        return app(CrewService::class)->problems($run);
    }

    /**
     * @return Collection<int, CrewAssignment>
     */
    public function crew(): Collection
    {
        return $this->runId === null ? new Collection : CrewAssignment::query()
            ->where('train_run_id', $this->runId)
            ->with('staff')
            ->get()
            ->sortBy(fn (CrewAssignment $a) => [array_search($a->duty, StaffDesignation::cases(), true), $a->staff->name])
            ->values();
    }

    /**
     * Active staff not yet working that day, grouped by designation.
     *
     * @return array<string, array<int, string>>
     */
    public function staffOptions(): array
    {
        $busy = CrewAssignment::whereDate('journey_date', $this->date)->pluck('staff_id')->all();
        $options = [];

        foreach (Staff::query()->where('is_active', true)->whereNotIn('id', $busy)->orderBy('name')->get() as $staff) {
            $note = $staff->designation->needsLicence() && ! $staff->hasValidLicence(CarbonImmutable::parse($this->date)) ? ' — licence expired' : '';
            $options[$staff->designation->getLabel()][$staff->id] = $staff->label().$note;
        }

        return $options;
    }

    /**
     * Every loco that is not retired; ones under repair are listed so the reason they can't be used is clear.
     *
     * @return array<int, string>
     */
    public function locoOptions(): array
    {
        return Locomotive::query()
            ->where('status', '!=', LocoStatus::Retired->value)
            ->orderBy('loco_no')
            ->get()
            ->mapWithKeys(fn (Locomotive $l) => [$l->id => $l->label().($l->status === LocoStatus::Available ? '' : ' — '.$l->status->getLabel())])
            ->all();
    }

    /**
     * @return array{licences: \Illuminate\Database\Eloquent\Collection<int, Staff>, locos: \Illuminate\Database\Eloquent\Collection<int, Locomotive>}
     */
    public function warnings(): array
    {
        return app(CrewService::class)->expiryWarnings();
    }

    public function updatedDate(): void
    {
        $this->runId = null;
        unset($this->runs);
    }

    public function selectRun(int $runId): void
    {
        $this->runId = $runId;
        $this->staffId = null;
        $this->locoId = null;
    }

    public function addCrew(CrewService $crew): void
    {
        abort_unless(static::canManage(), 403);
        $run = $this->run();
        $staff = $this->staffId === null ? null : Staff::find($this->staffId);

        if ($run === null || $staff === null) {
            return;
        }

        $this->attempt(fn () => $crew->assignCrew($run, $staff, $this->user()), "{$staff->name} assigned");
        $this->staffId = null;
    }

    public function assignLoco(CrewService $crew): void
    {
        abort_unless(static::canManage(), 403);
        $run = $this->run();
        $loco = $this->locoId === null ? null : Locomotive::find($this->locoId);

        if ($run === null || $loco === null) {
            return;
        }

        $this->attempt(fn () => $crew->assignLoco($run, $loco, $this->user()), "Loco {$loco->loco_no} assigned");
        $this->locoId = null;
    }

    public function removeCrew(int $assignmentId, CrewService $crew): void
    {
        abort_unless(static::canManage(), 403);
        $assignment = CrewAssignment::query()->where('train_run_id', $this->runId)->findOrFail($assignmentId);

        $this->attempt(fn () => $crew->unassignCrew($assignment), 'Removed');
    }

    public function removeLoco(CrewService $crew): void
    {
        abort_unless(static::canManage(), 403);
        $run = $this->run();

        if ($run !== null) {
            $this->attempt(fn () => $crew->unassignLoco($run), 'Loco removed');
        }
    }

    private function attempt(callable $change, string $success): void
    {
        try {
            $change();
            Notification::make()->title($success)->success()->send();
        } catch (DomainException $e) {
            Notification::make()->title('Not assigned')->body($e->getMessage())->danger()->send();
        }

        unset($this->runs);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
