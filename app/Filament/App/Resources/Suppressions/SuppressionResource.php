<?php

namespace App\Filament\App\Resources\Suppressions;

use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Filament\App\Resources\Suppressions\Pages\ManageSuppressions;
use App\Models\Suppression;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SuppressionResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = Suppression::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Suppression list';

    protected static ?string $modelLabel = 'suppression';

    protected static ?string $recordTitleAttribute = 'value';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('creator'))
            ->columns([
                TextColumn::make('value')
                    ->label('Email or domain')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('reason')->badge(),
                TextColumn::make('creator.name')->label('Added by')->placeholder('System'),
                TextColumn::make('created_at')->label('Added')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options(SuppressionType::class),
                SelectFilter::make('reason')->options(SuppressionReason::class),
            ])
            ->emptyStateHeading('Nobody is suppressed yet')
            ->emptyStateDescription('Unsubscribes, hard bounces and spam complaints land here automatically. Add competitors, existing customers or whole domains you never want to email.')
            ->recordActions([
                DeleteAction::make()
                    ->label('Remove')
                    ->modalDescription('This address or domain can be emailed again by your campaigns.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSuppressions::route('/'),
        ];
    }
}
