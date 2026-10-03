<?php

namespace App\Filament\App\Widgets\Analytics;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * A sortable table of KPIs per campaign, step or mailbox.
 */
abstract class BreakdownTable extends TableWidget
{
    use ReadsAnalyticsFilters;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return Collection<int, array<string, mixed>>
     */
    abstract protected function rows(): Collection;

    abstract protected function nameLabel(): string;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $sortColumn, ?string $sortDirection): Collection => filled($sortColumn)
                ? $this->rows()->sortBy($sortColumn, SORT_REGULAR, $sortDirection === 'desc')
                : $this->defaultOrder($this->rows()))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label($this->nameLabel())->wrap()->sortable(),
                ...$this->extraColumns(),
                $this->number('sent', 'Sent'),
                $this->rate('open_rate', 'Opened', 'opened'),
                $this->rate('click_rate', 'Clicked', 'clicked'),
                $this->rate('reply_rate', 'Replied', 'replied'),
                $this->rate('bounce_rate', 'Bounced', 'bounced'),
                $this->rate('unsubscribe_rate', 'Unsubscribed', 'unsubscribed'),
            ]);
    }

    /**
     * Most emails sent first.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    protected function defaultOrder(Collection $rows): Collection
    {
        return $rows->sortByDesc('sent');
    }

    /**
     * @return list<TextColumn>
     */
    protected function extraColumns(): array
    {
        return [];
    }

    protected function number(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->formatStateUsing(fn ($state): string => Number::format((int) $state))
            ->alignEnd()
            ->sortable();
    }

    protected function rate(string $name, string $label, string $count): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->formatStateUsing(fn ($state): string => "{$state}%")
            ->description(fn (array $record): string => Number::format($record[$count]))
            ->alignEnd()
            ->sortable();
    }
}
