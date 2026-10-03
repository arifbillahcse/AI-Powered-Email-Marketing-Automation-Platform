<?php

namespace App\Filament\App\Widgets\Analytics;

use Illuminate\Support\Collection;

class CampaignBreakdown extends BreakdownTable
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'By campaign';

    protected function rows(): Collection
    {
        return $this->report()->byCampaign($this->analyticsFilters());
    }

    protected function nameLabel(): string
    {
        return 'Campaign';
    }
}
