<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Workspaces\WorkspaceResource;
use App\Models\Workspace;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Workspaces whose last 7 days of sending look like abuse: bounce or
 * complaint rates over the auto-pause limits.
 */
class AbuseWatch extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Abuse watch (last 7 days)';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Workspace'),
                TextColumn::make('sent')->formatStateUsing(fn ($state): string => Number::format((int) $state)),
                TextColumn::make('bounce_rate')->label('Bounces')->formatStateUsing(fn ($state): string => "{$state}%")->color('danger'),
                TextColumn::make('complaint_rate')->label('Complaints')->formatStateUsing(fn ($state): string => "{$state}%")->color('danger'),
                TextColumn::make('suspended')->badge()->color(fn ($state): string => $state === 'Suspended' ? 'danger' : 'gray'),
            ])
            ->emptyStateHeading('Nothing suspicious')
            ->emptyStateDescription('No workspace is over the bounce or complaint limits.');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function rows(): Collection
    {
        $risk = WorkspaceResource::risk(fresh: true);
        $ids = WorkspaceResource::riskyWorkspaceIds();
        $workspaces = Workspace::query()->whereIn('id', $ids)->get(['id', 'name', 'suspended_at'])->keyBy('id');

        return collect($ids)
            ->filter(fn (int $id): bool => $workspaces->has($id))
            ->mapWithKeys(fn (int $id): array => [$id => [
                'name' => $workspaces[$id]->name,
                ...$risk[$id],
                'suspended' => $workspaces[$id]->suspended_at ? 'Suspended' : 'Active',
            ]])
            ->sortByDesc('complaint_rate');
    }
}
