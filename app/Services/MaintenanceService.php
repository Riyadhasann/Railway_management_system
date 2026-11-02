<?php

namespace App\Services;

use App\Enums\LocoStatus;
use App\Enums\MaintenanceType;
use App\Models\Locomotive;
use App\Models\Maintenance;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Sending locomotives to the workshop and back. A loco with open work is under repair
 * and can't be assigned to a train.
 */
class MaintenanceService
{
    /**
     * @throws DomainException
     */
    public function start(Locomotive $loco, MaintenanceType $type, CarbonInterface $startedOn, ?string $description = null): Maintenance
    {
        return DB::transaction(function () use ($loco, $type, $startedOn, $description) {
            /** @var Locomotive $locked */
            $locked = Locomotive::whereKey($loco->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === LocoStatus::Retired) {
                throw new DomainException("Loco {$locked->loco_no} is retired.");
            }

            if ($locked->maintenances()->whereNull('completed_on')->exists()) {
                throw new DomainException("Loco {$locked->loco_no} is already in the workshop.");
            }

            if ($startedOn->toDateString() > now()->toDateString()) {
                throw new DomainException('The work can\'t start in the future.');
            }

            $locked->update(['status' => LocoStatus::UnderRepair]);
            $loco->refresh();

            return $locked->maintenances()->create([
                'type' => $type,
                'started_on' => $startedOn->toDateString(),
                'description' => $description,
            ]);
        });
    }

    /**
     * Close the open work and put the loco back in service with its next due date.
     *
     * @throws DomainException
     */
    public function complete(Locomotive $loco, CarbonInterface $completedOn, CarbonInterface $nextDue): Maintenance
    {
        return DB::transaction(function () use ($loco, $completedOn, $nextDue) {
            /** @var Locomotive $locked */
            $locked = Locomotive::whereKey($loco->id)->lockForUpdate()->firstOrFail();

            /** @var Maintenance|null $open */
            $open = $locked->maintenances()->whereNull('completed_on')->latest('started_on')->first();

            if ($open === null) {
                throw new DomainException("Loco {$locked->loco_no} has no open workshop visit.");
            }

            // Compare calendar dates, not moments.
            $completed = $completedOn->toDateString();

            if ($completed < $open->started_on->toDateString() || $completed > now()->toDateString()) {
                throw new DomainException('The completion date must be between the start date and today.');
            }

            if ($nextDue->toDateString() <= $completed) {
                throw new DomainException('The next maintenance must be due after the completion date.');
            }

            $open->update(['completed_on' => $completedOn->toDateString()]);
            $locked->update([
                'status' => LocoStatus::Available,
                'last_maintenance_on' => $completedOn->toDateString(),
                'next_maintenance_due' => $nextDue->toDateString(),
            ]);
            $loco->refresh();

            return $open;
        });
    }
}
