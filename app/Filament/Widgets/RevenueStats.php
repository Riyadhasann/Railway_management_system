<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Services\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headline numbers for today, with 14-day sparklines.
 */
class RevenueStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /** Headline numbers show with the page; the charts below load lazily. */
    protected static ?bool $isLazyLoaded = false;

    protected ?string $heading = 'Today';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::ViewReports->value) ?? false;
    }

    protected function getStats(): array
    {
        $reports = app(ReportService::class);
        $today = CarbonImmutable::today();
        $days = $reports->dailyRevenue($today->subDays(13), $today);
        $now = collect($days)->last() ?? ['payments' => 0, 'refunds' => 0, 'net' => 0];
        $sold = $reports->ticketsSold($today, $today);
        $week = $reports->onTime($today->subDays(6), $today);
        $refunds = $reports->refunds($today, $today);
        $occupancy = collect($reports->occupancyByRun($today, $today->addDays(6)));
        $seats = (int) $occupancy->sum('seats');

        return [
            Stat::make('Revenue today', Money::format($now['net']))
                ->description('Payments '.Money::format($now['payments']).' − refunds '.Money::format($now['refunds']))
                ->chart(array_map(fn (array $d) => $d['net'] / 100, $days))
                ->color('success'),
            Stat::make('Tickets sold today', number_format($sold['passengers']))
                ->description(trans_choice(':count booking|:count bookings', $sold['bookings'])),
            Stat::make('Refunded today', Money::format($refunds['total']))
                ->description($refunds['open'] > 0 ? "{$refunds['open']} refund(s) waiting or failed" : 'No refunds waiting')
                ->color($refunds['open'] > 0 ? 'warning' : 'gray'),
            Stat::make('On time (7 days)', $week['arrived'] === 0 ? '—' : "{$week['percent']}%")
                ->description($week['arrived'] === 0 ? 'No arrivals recorded yet' : "{$week['on_time']} of {$week['arrived']} runs · avg {$week['average_delay']} min late"),
            Stat::make('Seats sold, next 7 days', $seats === 0 ? '—' : round($occupancy->sum('sold') * 100 / $seats, 1).'%')
                ->description(number_format((int) $occupancy->sum('sold')).' of '.number_format($seats).' seats'),
        ];
    }
}
