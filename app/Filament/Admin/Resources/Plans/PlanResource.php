<?php

namespace App\Filament\Admin\Resources\Plans;

use App\Filament\Admin\Resources\Concerns\SuperAdminOnly;
use App\Filament\Admin\Resources\Plans\Pages\ManagePlans;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PlanResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        $money = fn (string $name, string $label): TextInput => TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->step(0.01)
            ->required()
            ->formatStateUsing(fn ($state): string => number_format(((int) $state) / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state): int => (int) round(((float) $state) * 100));

        return $schema->components([
            Section::make('Plan')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(100),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(100)
                    ->alphaDash()
                    ->unique(ignoreRecord: true),
                Textarea::make('description')->rows(2)->maxLength(500)->columnSpanFull(),
            ]),
            Section::make('Price')->columns(4)->schema([
                Select::make('currency')
                    ->options(['USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'BDT' => 'BDT'])
                    ->default('USD')
                    ->required(),
                $money('price_monthly', 'Monthly price'),
                $money('price_yearly', 'Yearly price'),
                TextInput::make('trial_days')->label('Trial days')->numeric()->integer()->minValue(0)->maxValue(90)->default(14)->required(),
            ]),
            Section::make('Limits')
                ->description('Leave empty for unlimited.')
                ->columns(4)
                ->schema(collect(Plan::LIMITS)->map(fn (string $label, string $key): TextInput => TextInput::make("limits.{$key}")
                    ->label($label)
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->placeholder('Unlimited'))->values()->all()),
            Section::make('Features')->schema([
                CheckboxList::make('modules')
                    ->label('Modules included')
                    ->options(collect(modules()->all())->mapWithKeys(fn (array $module, string $key): array => [$key => $module['label']])->all())
                    ->columns(2),
            ]),
            Grid::make(3)->schema([
                Toggle::make('is_active')->label('Available for purchase')->default(true),
                Toggle::make('is_default')->label('Trial plan for new workspaces'),
                TextInput::make('sort')->numeric()->integer()->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('subscriptions'))
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->description(fn (Plan $record): string => $record->slug)->searchable(),
                TextColumn::make('price_monthly')->label('Monthly')->formatStateUsing(fn (Plan $record): string => Plan::formatMoney($record->price_monthly, $record->currency)),
                TextColumn::make('price_yearly')->label('Yearly')->formatStateUsing(fn (Plan $record): string => Plan::formatMoney($record->price_yearly, $record->currency)),
                TextColumn::make('limits')
                    ->label('Limits')
                    ->state(fn (Plan $record): array => collect(Plan::LIMITS)->map(fn (string $label, string $key): string => $label.': '.($record->limit($key) ?? '∞'))->values()->all())
                    ->listWithLineBreaks(),
                TextColumn::make('subscriptions_count')->label('Workspaces'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('is_default')->label('Trial plan')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (Plan $record): bool => $record->subscriptions_count === 0),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePlans::route('/'),
        ];
    }
}
