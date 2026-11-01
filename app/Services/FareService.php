<?php

namespace App\Services;

use App\Data\FareQuote;
use App\Enums\SeatClass;
use App\Exceptions\FareNotFoundException;
use App\Models\Concession;
use App\Models\FareRule;
use App\Models\RakeCoach;
use App\Models\Train;
use App\Models\TrainRun;
use InvalidArgumentException;

/**
 * Fares: a FareRule gives the per-seat fare for a class and distance band;
 * a Concession takes a percentage off one passenger's fare.
 */
class FareService
{
    /**
     * Base fare for each seat over the segment from -> to.
     *
     * @param  list<array{coach_no: string, seat_id: int}>  $seats
     */
    public function quote(TrainRun $run, array $seats, int $fromSeq, int $toSeq): FareQuote
    {
        $km = $this->distanceKm($run->train, $fromSeq, $toSeq);
        $classes = $run->train->rakeCoaches()->with('coachType')->get()
            ->mapWithKeys(fn (RakeCoach $c) => [$c->coach_no => $c->coachType->seat_class]);

        $fares = [];
        foreach ($seats as $seat) {
            $class = $classes[$seat['coach_no']]
                ?? throw new InvalidArgumentException("Coach {$seat['coach_no']} is not part of train {$run->train->number}.");
            $fares["{$seat['coach_no']}|{$seat['seat_id']}"] = $this->fareFor($class, $km);
        }

        return new FareQuote($km, $fares);
    }

    /**
     * Per-seat fare in poisha for a class and distance.
     *
     * @throws FareNotFoundException
     */
    public function fareFor(SeatClass $class, int $km): int
    {
        $rule = FareRule::query()
            ->where('seat_class', $class->value)
            ->where('km_from', '<=', $km)
            ->where('km_to', '>=', $km)
            ->orderBy('km_from')
            ->first();

        if ($rule === null) {
            throw FareNotFoundException::for($class, $km);
        }

        return $rule->fare_poisha;
    }

    /**
     * Per-seat fare for every class at this distance; classes without a rule are left out.
     *
     * @return array<string, int> seat class value => fare in poisha
     */
    public function faresByClass(int $km): array
    {
        return FareRule::query()
            ->where('km_from', '<=', $km)
            ->where('km_to', '>=', $km)
            ->orderBy('km_from')
            ->get()
            ->unique(fn (FareRule $r) => $r->seat_class->value)
            ->mapWithKeys(fn (FareRule $r) => [$r->seat_class->value => $r->fare_poisha])
            ->all();
    }

    /**
     * One passenger's fare after an optional concession, rounded to the nearest poisha.
     */
    public function applyConcession(int $farePoisha, ?Concession $concession): int
    {
        if ($concession === null) {
            return $farePoisha;
        }

        return intdiv($farePoisha * (100 - $concession->percent) + 50, 100);
    }

    /**
     * Distance in km between two stops of the train's route.
     */
    public function distanceKm(Train $train, int $fromSeq, int $toSeq): int
    {
        if ($fromSeq >= $toSeq) {
            throw new InvalidArgumentException('The journey must end after it starts.');
        }

        $km = $train->trainRoute->stops()
            ->whereIn('sequence', [$fromSeq, $toSeq])
            ->pluck('distance_km', 'sequence');

        if ($km->count() !== 2) {
            throw new InvalidArgumentException("Stops {$fromSeq} and {$toSeq} are not both on the route of train {$train->number}.");
        }

        return (int) $km[$toSeq] - (int) $km[$fromSeq];
    }

    /**
     * Validation for a fare band: km_to >= km_from and no overlap with another band of the class.
     *
     * @return list<string>
     */
    public function bandErrors(SeatClass $class, int $kmFrom, int $kmTo, ?int $ignoreId = null): array
    {
        if ($kmTo < $kmFrom) {
            return ['The band must end at or after its start.'];
        }

        $clash = FareRule::query()
            ->overlappingBand($class, $kmFrom, $kmTo)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();

        return $clash
            ? ["Overlaps the {$clash->km_from}-{$clash->km_to} km band for {$class->getLabel()}."]
            : [];
    }
}
