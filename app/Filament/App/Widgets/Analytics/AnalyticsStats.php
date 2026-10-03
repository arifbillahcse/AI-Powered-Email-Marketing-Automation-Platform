<?php

namespace App\Filament\App\Widgets\Analytics;

use App\Services\Analytics\AnalyticsReport;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Headline KPIs. Rates are a share of the emails sent in the period.
 */
class AnalyticsStats extends StatsOverviewWidget
{
    use ReadsAnalyticsFilters;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected function getHeading(): ?string
    {
        return 'Emails sent '.$this->analyticsFilters()->label();
    }

    protected function getStats(): array
    {
        $filters = $this->analyticsFilters();
        $totals = $this->report()->totals($filters);
        $rates = AnalyticsReport::rates($totals);
        $daily = collect($this->report()->daily($filters));
        $count = fn (string $metric): string => Number::format($totals[$metric]).' '.str('email')->plural($totals[$metric]);

        return [
            Stat::make('Sent', Number::format($totals['sent']))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->chart($daily->pluck('sent')->values()->all())
                ->color('primary'),
            Stat::make('Open rate', "{$rates['open']}%")
                ->description($count('opened').' opened')
                ->chart($daily->pluck('opened')->values()->all())
                ->color('info'),
            Stat::make('Click rate', "{$rates['click']}%")
                ->description($count('clicked').' clicked')
                ->color('info'),
            Stat::make('Reply rate', "{$rates['reply']}%")
                ->description($count('replied').' replied to')
                ->chart($daily->pluck('replied')->values()->all())
                ->color('success'),
            Stat::make('Bounce rate', "{$rates['bounce']}%")
                ->description($count('bounced').' bounced')
                ->descriptionColor($rates['bounce'] > 2 ? 'danger' : 'gray')
                ->color($rates['bounce'] > 2 ? 'danger' : 'gray'),
            Stat::make('Unsubscribe rate', "{$rates['unsubscribe']}%")
                ->description(Number::format($totals['unsubscribed']).' unsubscribed')
                ->descriptionColor($rates['unsubscribe'] > 1 ? 'warning' : 'gray')
                ->color($rates['unsubscribe'] > 1 ? 'warning' : 'gray'),
        ];
    }
}
