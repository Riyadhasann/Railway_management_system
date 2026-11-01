<?php

namespace App\Services;

use App\Data\TrainOption;
use App\Enums\Channel;
use App\Models\ScheduleStop;
use App\Models\Station;
use App\Models\Train;
use App\Models\TrainRun;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Passenger search: which trains go from one station to another on a travel date,
 * with free seats and fare per class.
 */
class TrainSearchService
{
    public function __construct(
        private readonly SeatInventoryService $inventory,
        private readonly FareService $fares,
    ) {}

    /**
     * The travel date is the day the passenger boards. For an overnight train boarding
     * after midnight, that is a day after the run's journey date (day_offset).
     *
     * @return list<TrainOption> ordered by departure
     */
    public function search(Station $from, Station $to, CarbonImmutable $date): array
    {
        if ($from->is($to)) {
            return [];
        }

        $options = [];

        $trains = Train::query()
            ->where('is_active', true)
            ->with(['scheduleStops.routeStop', 'rakeCoaches.coachType'])
            ->get();

        foreach ($trains as $train) {
            $board = $train->scheduleStops->first(fn (ScheduleStop $s) => $s->routeStop->station_id === $from->id && $s->departure_time !== null);
            $alight = $train->scheduleStops->first(fn (ScheduleStop $s) => $s->routeStop->station_id === $to->id && $s->arrival_time !== null);

            if ($board === null || $alight === null || $board->routeStop->sequence >= $alight->routeStop->sequence) {
                continue;
            }

            $journeyDate = $date->startOfDay()->subDays($board->day_offset);
            $run = TrainRun::query()
                ->where('train_id', $train->id)
                ->whereDate('journey_date', $journeyDate->toDateString())
                ->first();

            if ($run === null) {
                continue;
            }

            $run->setRelation('train', $train);
            $options[] = $this->option($run, $board, $alight);
        }

        usort($options, fn (TrainOption $a, TrainOption $b) => $a->departure <=> $b->departure);

        return $options;
    }

    private function option(TrainRun $run, ScheduleStop $board, ScheduleStop $alight): TrainOption
    {
        $fromSeq = $board->routeStop->sequence;
        $toSeq = $alight->routeStop->sequence;
        $km = $alight->routeStop->distance_km - $board->routeStop->distance_km;
        $fares = $this->fares->faresByClass($km);

        $available = [];
        foreach ($this->inventory->availability($run, $fromSeq, $toSeq, Channel::Online) as $coach) {
            $available[$coach->seatClass->value] = ($available[$coach->seatClass->value] ?? ['class' => $coach->seatClass, 'available' => 0]);
            $available[$coach->seatClass->value]['available'] += $coach->availableCount();
        }

        $classes = array_values(array_map(fn (array $c) => [
            'class' => $c['class'],
            'available' => $c['available'],
            'fare' => $fares[$c['class']->value] ?? null,
        ], $available));

        // Both times exist: search() only picks stops with a departure / arrival time.
        $departure = $board->departureOn($run->journey_date) ?? throw new LogicException('Boarding stop has no departure time.');
        $arrival = $alight->arrivalOn($run->journey_date) ?? throw new LogicException('Alighting stop has no arrival time.');

        return new TrainOption($run, $fromSeq, $toSeq, $departure, $arrival, $km, $classes);
    }
}
