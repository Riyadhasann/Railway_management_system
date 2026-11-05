<?php

namespace App\Filament\Widgets;

/**
 * Net revenue per train, highest first.
 */
class RevenueByTrainChart extends ReportChartWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Revenue by train (৳)';

    protected function getData(): array
    {
        $rows = $this->reports()->revenueBy('train', ...$this->period());

        return [
            'datasets' => [
                ['label' => 'Net', 'data' => array_map(fn ($r) => self::taka($r['net']), $rows), 'backgroundColor' => self::COLORS[0]],
                ['label' => 'Refunds', 'data' => array_map(fn ($r) => self::taka($r['refunds']), $rows), 'backgroundColor' => self::COLORS[2]],
            ],
            'labels' => array_column($rows, 'label'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
