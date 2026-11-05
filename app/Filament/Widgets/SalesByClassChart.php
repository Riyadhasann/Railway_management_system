<?php

namespace App\Filament\Widgets;

/**
 * Ticket sales per seat class (before refunds).
 */
class SalesByClassChart extends ReportChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Ticket sales by class (৳, before refunds)';

    protected function getData(): array
    {
        $rows = $this->reports()->salesByClass(...$this->period());

        return [
            'datasets' => [[
                'label' => 'Sales',
                'data' => array_map(fn ($r) => self::taka($r['sales']), $rows),
                'backgroundColor' => array_slice(self::COLORS, 0, max(1, count($rows))),
            ]],
            'labels' => array_column($rows, 'label'),
        ];
    }

    protected function getType(): string
    {
        return 'pie';
    }
}
