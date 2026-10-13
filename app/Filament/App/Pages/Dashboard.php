<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\Analytics\AnalyticsStats;
use App\Filament\App\Widgets\OnboardingChecklist;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

/**
 * The workspace home: the get-started checklist (until it's done), who
 * you are and the last 30 days at a glance.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            OnboardingChecklist::class,
            AccountWidget::class,
            AnalyticsStats::class,
        ];
    }
}
