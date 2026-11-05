<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Report tables for download: one report as CSV, or every report as sheets of one Excel file.
 * Money is written in taka with two decimals so spreadsheets can sum it.
 */
class ReportExporter
{
    /** key => title */
    public const array REPORTS = [
        'daily_revenue' => 'Daily revenue',
        'revenue_by_train' => 'Revenue by train',
        'revenue_by_channel' => 'Revenue by channel',
        'sales_by_class' => 'Sales by class',
        'occupancy' => 'Occupancy by run',
        'refunds' => 'Refunds by reason',
    ];

    public function __construct(private readonly ReportService $reports) {}

    /**
     * Header row and data rows of one report.
     *
     * @return array{0: list<string>, 1: list<list<string|int|float>>}
     */
    public function table(string $report, CarbonInterface $from, CarbonInterface $to): array
    {
        return match ($report) {
            'daily_revenue' => [
                ['Date', 'Payments (Tk)', 'Refunds (Tk)', 'Net (Tk)'],
                array_map(fn ($r) => [$r['date'], self::taka($r['payments']), self::taka($r['refunds']), self::taka($r['net'])], $this->reports->dailyRevenue($from, $to)),
            ],
            'revenue_by_train', 'revenue_by_channel' => [
                [$report === 'revenue_by_train' ? 'Train' : 'Channel', 'Payments (Tk)', 'Refunds (Tk)', 'Net (Tk)'],
                array_map(fn ($r) => [$r['label'], self::taka($r['payments']), self::taka($r['refunds']), self::taka($r['net'])], $this->reports->revenueBy($report === 'revenue_by_train' ? 'train' : 'channel', $from, $to)),
            ],
            'sales_by_class' => [
                ['Class', 'Seats sold', 'Sales before refunds (Tk)'],
                array_map(fn ($r) => [$r['label'], $r['seats'], self::taka($r['sales'])], $this->reports->salesByClass($from, $to)),
            ],
            'occupancy' => [
                ['Journey date', 'Train', 'Seats', 'Sold', 'Occupancy %'],
                array_map(fn ($r) => [$r['date'], $r['train'], $r['seats'], $r['sold'], $r['percent']], $this->reports->occupancyByRun($from, $to)),
            ],
            'refunds' => [
                ['Reason', 'Refunds', 'Amount (Tk)'],
                array_map(fn ($r) => [$r['label'], $r['count'], self::taka($r['amount'])], $this->reports->refunds($from, $to)['rows']),
            ],
            default => throw new InvalidArgumentException("Unknown report {$report}."),
        };
    }

    /**
     * Write one report as CSV. Returns the file path (the caller deletes it).
     */
    public function csv(string $report, CarbonInterface $from, CarbonInterface $to): string
    {
        [$header, $rows] = $this->table($report, $from, $to);
        $path = $this->tempPath('csv');

        $writer = new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($header));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    /**
     * Write every report as a sheet of one workbook. Returns the file path (the caller deletes it).
     */
    public function xlsx(CarbonInterface $from, CarbonInterface $to): string
    {
        $path = $this->tempPath('xlsx');
        $bold = (new Style)->setFontBold();

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $first = true;

        foreach (self::REPORTS as $key => $title) {
            if (! $first) {
                $writer->addNewSheetAndMakeItCurrent();
            }
            $first = false;
            $writer->getCurrentSheet()->setName($title);

            [$header, $rows] = $this->table($key, $from, $to);
            $writer->addRow(Row::fromValues(["{$title}, {$from->format('d M Y')} to {$to->format('d M Y')}"], $bold));
            $writer->addRow(Row::fromValues($header, $bold));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        return $path;
    }

    public function filename(string $report, CarbonInterface $from, CarbonInterface $to, string $extension): string
    {
        return str_replace('_', '-', $report)."-{$from->toDateString()}-to-{$to->toDateString()}.{$extension}";
    }

    private function tempPath(string $extension): string
    {
        $dir = storage_path('app/private/exports');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir.DIRECTORY_SEPARATOR.CarbonImmutable::now()->format('YmdHis').'-'.bin2hex(random_bytes(4)).'.'.$extension;
    }

    private static function taka(int $poisha): float
    {
        return round($poisha / 100, 2);
    }
}
