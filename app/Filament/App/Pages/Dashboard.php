<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\Analytics\AnalyticsStats;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

/**
 * The workspace home: who you are and the last 30 days at a glance.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            AccountWidget::class,
            AnalyticsStats::class,
        ];
    }
}
