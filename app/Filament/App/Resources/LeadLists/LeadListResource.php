<?php

namespace App\Filament\App\Resources\LeadLists;

use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Filament\App\Resources\LeadLists\Pages\ManageLeadLists;
use App\Filament\App\Resources\Leads\LeadResource;
use App\Models\LeadList;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class LeadListResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = LeadList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'list';

    protected static ?string $navigationLabel = 'Lists';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('workspace_id', Filament::getTenant()?->getKey()),
                ),
            Textarea::make('description')->rows(2)->maxLength(1000),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('leads'))
            ->columns([
                TextColumn::make('name')
                    ->description(fn (LeadList $record): ?string => $record->description)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('leads_count')
                    ->label('Leads')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No lists yet')
            ->emptyStateDescription('Create a list, or let a CSV import create one for you.')
            ->recordUrl(fn (LeadList $record): string => static::leadsUrl($record))
            ->recordActions([
                Action::make('viewLeads')
                    ->label('View leads')
                    ->icon(Heroicon::OutlinedUsers)
                    ->url(fn (LeadList $record): string => static::leadsUrl($record)),
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Deletes the list only. Its leads stay in your workspace.'),
            ]);
    }

    public static function leadsUrl(LeadList $list): string
    {
        return LeadResource::getUrl('index', ['filters' => ['lists' => ['values' => [$list->getKey()]]]]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLeadLists::route('/'),
        ];
    }
}
