<?php

namespace App\Filament\App\Widgets\Analytics;

use App\Models\Workspace;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsReport;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Analytics widgets read the page's filter form (or default to the last
 * 30 days when shown elsewhere, e.g. on the dashboard).
 */
trait ReadsAnalyticsFilters
{
    use InteractsWithPageFilters;

    protected function analyticsFilters(): AnalyticsFilters
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();

        return AnalyticsFilters::fromState($workspace, $this->pageFilters);
    }

    protected function report(): AnalyticsReport
    {
        return app(AnalyticsReport::class);
    }
}
