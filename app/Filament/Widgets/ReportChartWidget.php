<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * Dashboard chart for finance, managers and admins, with a "last N days" filter.
 */
abstract class ReportChartWidget extends ChartWidget
{
    /** Fixed palette so a series keeps its colour on every chart. */
    public const array COLORS = ['#059669', '#0284c7', '#e11d48', '#d97706', '#7c3aed', '#0d9488', '#db2777', '#4b5563'];

    public ?string $filter = '30';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::ViewReports->value) ?? false;
    }

    /**
     * Number of days => label.
     *
     * @return array<int|string, string>
     */
    protected function getFilters(): ?array
    {
        return ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function period(): array
    {
        $days = max(1, (int) ($this->filter ?? 30));

        return [CarbonImmutable::today()->subDays($days - 1), CarbonImmutable::today()];
    }

    protected function reports(): ReportService
    {
        return app(ReportService::class);
    }

    protected static function taka(int $poisha): float
    {
        return round($poisha / 100, 2);
    }
}
