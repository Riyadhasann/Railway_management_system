<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Services\ReportExporter;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Report tables for a date range, with CSV download of one report and an Excel
 * workbook of all of them.
 */
class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Reports and export';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.reports';

    /** The longest range one report may cover. */
    public const int MAX_DAYS = 366;

    public string $from = '';

    public string $to = '';

    public string $report = 'daily_revenue';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ViewReports->value) ?? false;
    }

    public function getTitle(): string
    {
        return 'Reports and export';
    }

    public function mount(): void
    {
        $this->to = now()->toDateString();
        $this->from = now()->subDays(29)->toDateString();
    }

    /**
     * @return array{0: list<string>, 1: list<list<string|int|float>>}|null null when the range is not valid
     */
    public function table(): ?array
    {
        $range = $this->range();

        return $range === null || ! isset(ReportExporter::REPORTS[$this->report])
            ? null
            : app(ReportExporter::class)->table($this->report, ...$range);
    }

    public function rangeError(): ?string
    {
        return $this->range() === null ? 'Choose a start date on or before the end date, at most '.self::MAX_DAYS.' days apart.' : null;
    }

    public function downloadCsv(ReportExporter $exporter): ?BinaryFileResponse
    {
        abort_unless(static::canAccess(), 403);
        $range = $this->range();

        if ($range === null || ! isset(ReportExporter::REPORTS[$this->report])) {
            Notification::make()->title('Choose a valid report and date range.')->warning()->send();

            return null;
        }

        return response()->download($exporter->csv($this->report, ...$range), $exporter->filename($this->report, $range[0], $range[1], 'csv'), ['Content-Type' => 'text/csv'])->deleteFileAfterSend();
    }

    public function downloadExcel(ReportExporter $exporter): ?BinaryFileResponse
    {
        abort_unless(static::canAccess(), 403);
        $range = $this->range();

        if ($range === null) {
            Notification::make()->title('Choose a valid date range.')->warning()->send();

            return null;
        }

        return response()->download($exporter->xlsx(...$range), $exporter->filename('railway_reports', $range[0], $range[1], 'xlsx'))->deleteFileAfterSend();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function range(): ?array
    {
        if ($this->from === '' || $this->to === '') {
            return null;
        }

        try {
            $from = CarbonImmutable::parse($this->from);
            $to = CarbonImmutable::parse($this->to);
        } catch (\Throwable) {
            return null;
        }

        return $from->lte($to) && $from->diffInDays($to) < self::MAX_DAYS ? [$from, $to] : null;
    }
}
