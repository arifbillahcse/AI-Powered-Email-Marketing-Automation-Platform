<?php

namespace App\Filament\App\Widgets\Analytics;

use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Compare the emails of one campaign's sequence (pick it in the filters).
 */
class StepBreakdown extends BreakdownTable
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'By email in the sequence';

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->emptyStateHeading($this->analyticsFilters()->campaignId ? 'Nothing sent in this period' : 'Pick a campaign')
            ->emptyStateDescription($this->analyticsFilters()->campaignId ? null : 'Choose a campaign in the filters above to compare each email of its sequence.');
    }

    protected function rows(): Collection
    {
        return $this->report()->byStep($this->analyticsFilters());
    }

    /**
     * Sequence order.
     */
    protected function defaultOrder(Collection $rows): Collection
    {
        return $rows->sortKeys();
    }

    protected function nameLabel(): string
    {
        return 'Email';
    }
}
