<?php

namespace App\Services;

use App\Enums\LocoStatus;
use App\Enums\RunStatus;
use App\Enums\StaffDesignation;
use App\Models\CrewAssignment;
use App\Models\LocoAssignment;
use App\Models\Locomotive;
use App\Models\Staff;
use App\Models\TrainRun;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Crew and locomotive assignment to train runs. The rules:
 *  - only scheduled or running runs can be staffed;
 *  - crew must be active, and drivers must hold a licence valid on the journey date;
 *  - a person, and a loco, works one run per journey date;
 *  - a loco must be available (not under repair or retired).
 */
class CrewService
{
    /** Warn about driving licences expiring within this many days. */
    public const int LICENCE_WARNING_DAYS = 30;

    /** Warn about loco maintenance due within this many days. */
    public const int MAINTENANCE_WARNING_DAYS = 7;

    /**
     * @throws DomainException
     */
    public function assignCrew(TrainRun $run, Staff $staff, User $by): CrewAssignment
    {
        return DB::transaction(function () use ($run, $staff, $by) {
            $run = $this->lockRun($run);

            if (! $staff->is_active) {
                throw new DomainException("{$staff->name} is not active.");
            }

            if (! $staff->hasValidLicence($run->journey_date)) {
                throw new DomainException($staff->licence_expires_on === null
                    ? "{$staff->name} has no driving licence on record."
                    : "{$staff->name}'s driving licence expired on {$staff->licence_expires_on->format('d M Y')}.");
            }

            $existing = CrewAssignment::query()
                ->where('staff_id', $staff->id)
                ->whereDate('journey_date', $run->journey_date->toDateString())
                ->with('trainRun.train')
                ->first();

            if ($existing !== null) {
                throw new DomainException($existing->train_run_id === $run->id
                    ? "{$staff->name} is already on this train."
                    : "{$staff->name} is already working train {$existing->trainRun->train->label()} that day.");
            }

            return CrewAssignment::create([
                'train_run_id' => $run->id,
                'staff_id' => $staff->id,
                'duty' => $staff->designation,
                'journey_date' => $run->journey_date->toDateString(),
                'assigned_by' => $by->id,
            ]);
        });
    }

    /**
     * Put a loco on the run, replacing any loco assigned before.
     *
     * @throws DomainException
     */
    public function assignLoco(TrainRun $run, Locomotive $loco, User $by): LocoAssignment
    {
        return DB::transaction(function () use ($run, $loco, $by) {
            $run = $this->lockRun($run);
            /** @var Locomotive $loco */
            $loco = Locomotive::whereKey($loco->id)->lockForUpdate()->firstOrFail();

            if ($loco->status !== LocoStatus::Available) {
                throw new DomainException("Loco {$loco->loco_no} is {$loco->status->getLabel()} and can't be assigned.");
            }

            $elsewhere = LocoAssignment::query()
                ->where('locomotive_id', $loco->id)
                ->whereDate('journey_date', $run->journey_date->toDateString())
                ->where('train_run_id', '!=', $run->id)
                ->with('trainRun.train')
                ->first();

            if ($elsewhere !== null) {
                throw new DomainException("Loco {$loco->loco_no} is already hauling train {$elsewhere->trainRun->train->label()} that day.");
            }

            return LocoAssignment::updateOrCreate(
                ['train_run_id' => $run->id],
                ['locomotive_id' => $loco->id, 'journey_date' => $run->journey_date->toDateString(), 'assigned_by' => $by->id],
            );
        });
    }

    public function unassignCrew(CrewAssignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $this->lockRun($assignment->trainRun);
            $assignment->delete();
        });
    }

    public function unassignLoco(TrainRun $run): void
    {
        DB::transaction(function () use ($run) {
            $this->lockRun($run);
            $run->locoAssignment()->delete();
        });
    }

    /**
     * What still needs attention before the run can leave.
     *
     * @return list<string>
     */
    public function problems(TrainRun $run): array
    {
        $crew = $run->crewAssignments()->with('staff')->get();
        $loco = $run->locoAssignment()->with('locomotive')->first();
        $problems = [];

        if (! $crew->contains(fn (CrewAssignment $a) => $a->duty === StaffDesignation::Driver)) {
            $problems[] = 'No driver assigned.';
        }

        if (! $crew->contains(fn (CrewAssignment $a) => $a->duty === StaffDesignation::Guard)) {
            $problems[] = 'No guard assigned.';
        }

        if ($loco === null) {
            $problems[] = 'No locomotive assigned.';
        } elseif ($loco->locomotive->status !== LocoStatus::Available) {
            $problems[] = "Loco {$loco->locomotive->loco_no} is now {$loco->locomotive->status->getLabel()}: assign another.";
        }

        foreach ($crew as $a) {
            if (! $a->staff->hasValidLicence($run->journey_date)) {
                $problems[] = "{$a->staff->name}'s licence is no longer valid on the journey date.";
            }
        }

        return $problems;
    }

    /**
     * Active drivers whose licence has expired or expires within $licenceDays, and working
     * locos whose maintenance is overdue or due within $maintenanceDays (soonest first).
     *
     * @return array{licences: Collection<int, Staff>, locos: Collection<int, Locomotive>}
     */
    public function expiryWarnings(int $licenceDays = self::LICENCE_WARNING_DAYS, int $maintenanceDays = self::MAINTENANCE_WARNING_DAYS): array
    {
        $licences = Staff::query()
            ->where('is_active', true)
            ->whereIn('designation', [StaffDesignation::Driver->value, StaffDesignation::AssistantDriver->value])
            ->where(fn ($q) => $q
                ->whereNull('licence_expires_on')
                ->orWhereDate('licence_expires_on', '<=', now()->addDays($licenceDays)->toDateString()))
            ->orderBy('licence_expires_on')
            ->get();

        $locos = Locomotive::query()
            ->where('status', '!=', LocoStatus::Retired->value)
            ->whereDate('next_maintenance_due', '<=', now()->addDays($maintenanceDays)->toDateString())
            ->orderBy('next_maintenance_due')
            ->get();

        return ['licences' => $licences, 'locos' => $locos];
    }

    private function lockRun(TrainRun $run): TrainRun
    {
        /** @var TrainRun $locked */
        $locked = TrainRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, [RunStatus::Scheduled, RunStatus::Running], true)) {
            throw new DomainException("This run is {$locked->status->getLabel()}; its crew can't be changed.");
        }

        return $locked;
    }
}
