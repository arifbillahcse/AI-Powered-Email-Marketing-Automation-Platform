<?php

namespace App\Filament\App\Resources\Segments;

use App\Enums\LeadStatus;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Filament\App\Resources\Leads\LeadResource;
use App\Filament\App\Resources\Segments\Pages\CreateSegment;
use App\Filament\App\Resources\Segments\Pages\EditSegment;
use App\Filament\App\Resources\Segments\Pages\ListSegments;
use App\Models\LeadList;
use App\Models\Segment;
use App\Services\Leads\SegmentQuery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class SegmentResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = Segment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $field = fn (Get $get): ?string => $get('field');
        $isText = fn (Get $get): bool => array_key_exists((string) $get('field'), SegmentQuery::TEXT_FIELDS) || $get('field') === 'custom';

        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(100)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('workspace_id', Filament::getTenant()?->getKey()),
                        ),
                    Radio::make('match')
                        ->label('Leads must match')
                        ->options(['all' => 'All rules', 'any' => 'Any rule'])
                        ->default('all')
                        ->inline()
                        ->required(),
                ]),
            Repeater::make('rules')
                ->columnSpanFull()
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel('Add rule')
                ->columns(4)
                ->schema([
                    Select::make('field')
                        ->options(SegmentQuery::fieldOptions())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('operator', null);
                            $set('value', null);
                        }),
                    TextInput::make('key')
                        ->label('Custom field')
                        ->placeholder('company_size')
                        ->visible(fn (Get $get): bool => $get('field') === 'custom')
                        ->required(fn (Get $get): bool => $get('field') === 'custom'),
                    Select::make('operator')
                        ->options(fn (Get $get): array => SegmentQuery::operatorOptions($field($get)))
                        ->required()
                        ->live(),
                    TextInput::make('value')
                        ->visible(fn (Get $get): bool => ($isText($get) || $get('field') === 'tag') && SegmentQuery::needsValue($get('operator')))
                        ->required(fn (Get $get): bool => ($isText($get) || $get('field') === 'tag') && SegmentQuery::needsValue($get('operator'))),
                    Select::make('value')
                        ->label('Status')
                        ->options(LeadStatus::class)
                        ->visible(fn (Get $get): bool => $get('field') === 'status')
                        ->required(fn (Get $get): bool => $get('field') === 'status'),
                    Select::make('value')
                        ->label('List')
                        ->options(fn (): array => LeadList::query()->where('workspace_id', Filament::getTenant()?->getKey())->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => $get('field') === 'list')
                        ->required(fn (Get $get): bool => $get('field') === 'list'),
                    DatePicker::make('value')
                        ->label('Date')
                        ->visible(fn (Get $get): bool => $get('field') === 'created_at')
                        ->required(fn (Get $get): bool => $get('field') === 'created_at'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('rules')
                    ->label('Rules')
                    ->state(fn (Segment $record): string => count($record->rules ?? []).' '.($record->match === 'any' ? '(any)' : '(all)')),
                TextColumn::make('leads')
                    ->label('Leads now')
                    ->state(fn (Segment $record): string => Number::format($record->leadsQuery()->count())),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No segments yet')
            ->emptyStateDescription('Segments are saved filters, e.g. "Founders in the US tagged hot". Campaigns can target them.')
            ->recordActions([
                Action::make('viewLeads')
                    ->label('View leads')
                    ->icon(Heroicon::OutlinedUsers)
                    ->url(fn (Segment $record): string => LeadResource::getUrl('index', ['filters' => ['segment' => ['value' => $record->getKey()]]])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSegments::route('/'),
            'create' => CreateSegment::route('/create'),
            'edit' => EditSegment::route('/{record}/edit'),
        ];
    }
}
