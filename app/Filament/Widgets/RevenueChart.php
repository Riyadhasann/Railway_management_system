<?php

namespace App\Filament\Widgets;

use Carbon\CarbonImmutable;

/**
 * Daily payments, refunds and net revenue.
 */
class RevenueChart extends ReportChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Daily revenue (৳)';

    protected function getData(): array
    {
        $days = $this->reports()->dailyRevenue(...$this->period());

        return [
            'datasets' => [
                ['label' => 'Net', 'data' => array_map(fn ($d) => self::taka($d['net']), $days), 'borderColor' => self::COLORS[0], 'backgroundColor' => self::COLORS[0].'33', 'fill' => true, 'tension' => 0.3],
                ['label' => 'Payments', 'data' => array_map(fn ($d) => self::taka($d['payments']), $days), 'borderColor' => self::COLORS[1], 'backgroundColor' => self::COLORS[1], 'tension' => 0.3],
                ['label' => 'Refunds', 'data' => array_map(fn ($d) => self::taka($d['refunds']), $days), 'borderColor' => self::COLORS[2], 'backgroundColor' => self::COLORS[2], 'tension' => 0.3],
            ],
            'labels' => array_map(fn ($d) => CarbonImmutable::parse($d['date'])->format('d M'), $days),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
