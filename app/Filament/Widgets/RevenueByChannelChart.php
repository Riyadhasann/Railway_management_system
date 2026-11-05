<?php

namespace App\Filament\Widgets;

/**
 * Net revenue, online versus counter.
 */
class RevenueByChannelChart extends ReportChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Revenue by channel (৳)';

    protected function getData(): array
    {
        $rows = $this->reports()->revenueBy('channel', ...$this->period());

        return [
            'datasets' => [[
                'label' => 'Net revenue',
                'data' => array_map(fn ($r) => self::taka($r['net']), $rows),
                'backgroundColor' => array_slice(self::COLORS, 0, max(1, count($rows))),
            ]],
            'labels' => array_column($rows, 'label'),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
