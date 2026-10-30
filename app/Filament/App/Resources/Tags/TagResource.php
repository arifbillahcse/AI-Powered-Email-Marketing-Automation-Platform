<?php

namespace App\Filament\App\Resources\Tags;

use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Filament\App\Resources\Tags\Pages\ManageTags;
use App\Models\Tag;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Tag manager: rename and color the labels you put on leads. Tags are
 * created by typing them on a lead; this is where they get their color.
 */
class TagResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 28;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'tags';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->placeholder('hot lead')
                ->helperText('Lowercase; shown on the lead and usable as a filter.')
                ->required()
                ->maxLength(50)
                ->dehydrateStateUsing(fn (string $state): string => Tag::normalizeName($state))
                ->rules([
                    fn (?Tag $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        $taken = Tag::query()
                            ->where('workspace_id', Filament::getTenant()?->getKey())
                            ->where('name', Tag::normalizeName((string) $value))
                            ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                            ->exists();

                        if ($taken) {
                            $fail('You already have a tag with this name.');
                        }
                    },
                ]),
            Select::make('color')
                ->options(Tag::COLORS)
                ->default('gray')
                ->required()
                ->native(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('leads'))
            ->columns([
                TextColumn::make('name')
                    ->badge()
                    ->color(fn (Tag $record): string => $record->color)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('color')
                    ->formatStateUsing(fn (string $state): string => Tag::COLORS[$state] ?? $state)
                    ->color('gray'),
                TextColumn::make('leads_count')->label('Leads')->numeric()->sortable(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No tags yet')
            ->emptyStateDescription('Tag a lead with the Tags button, then give the tag a color here.')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('The tag is removed from every lead that has it. The leads themselves stay.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTags::route('/'),
        ];
    }
}
