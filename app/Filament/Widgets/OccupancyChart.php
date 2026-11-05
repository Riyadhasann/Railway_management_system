<?php

namespace App\Filament\Widgets;

use Carbon\CarbonImmutable;

/**
 * Seats sold per run (% of the rake), for the coming or the past week.
 */
class OccupancyChart extends ReportChartWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Occupancy per run (% of seats sold)';

    public ?string $filter = 'next';

    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return ['next' => 'Today and next 6 days', 'past' => 'Last 7 days'];
    }

    protected function getData(): array
    {
        $today = CarbonImmutable::today();
        [$from, $to] = $this->filter === 'past' ? [$today->subDays(7), $today->subDay()] : [$today, $today->addDays(6)];
        $rows = $this->reports()->occupancyByRun($from, $to);

        return [
            'datasets' => [[
                'label' => 'Occupancy %',
                'data' => array_column($rows, 'percent'),
                'backgroundColor' => array_map(fn ($r) => $r['percent'] >= 80 ? self::COLORS[0] : ($r['percent'] >= 40 ? self::COLORS[1] : self::COLORS[3]), $rows),
            ]],
            'labels' => array_map(fn ($r) => CarbonImmutable::parse($r['date'])->format('d M').' · '.$r['train'], $rows),
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['min' => 0, 'max' => 100]], 'plugins' => ['legend' => ['display' => false]]];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
