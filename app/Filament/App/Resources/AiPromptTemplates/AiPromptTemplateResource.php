<?php

namespace App\Filament\App\Resources\AiPromptTemplates;

use App\Enums\AiContentType;
use App\Filament\App\Resources\AiPromptTemplates\Pages\ManageAiPromptTemplates;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Models\AiPromptTemplate;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Reusable AI instructions: what you sell, to whom, and how to write it.
 */
class AiPromptTemplateResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = AiPromptTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 26;

    protected static ?string $navigationLabel = 'AI prompts';

    protected static ?string $modelLabel = 'AI prompt';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'ai-prompts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::formComponents())->columns(2);
    }

    /**
     * Also used to create a prompt from the campaign's "AI personalize" action.
     *
     * @return array<Component>
     */
    public static function formComponents(?AiContentType $type = null, bool $editing = true): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->unique(
                    table: 'ai_prompt_templates',
                    ignoreRecord: $editing,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('workspace_id', Filament::getTenant()?->getKey()),
                ),
            Select::make('type')
                ->label('Writes')
                ->options(AiContentType::class)
                ->default($type?->value ?? AiContentType::FirstLine->value)
                ->required(),
            Textarea::make('instructions')
                ->label('What should the AI know?')
                ->helperText('Who you are, what you offer, who the leads are and anything to mention or avoid. The same for every lead; their details are added automatically.')
                ->placeholder("We're Softorio, a web agency in Dhaka. We build fast WordPress and WooCommerce sites for small businesses. Mention something specific about their website or company. Don't mention prices.")
                ->rows(6)
                ->required()
                ->maxLength(5000)
                ->columnSpanFull(),
            Select::make('tone')
                ->options(AiPromptTemplate::TONES)
                ->default('friendly')
                ->required(),
            Select::make('length')
                ->options(AiPromptTemplate::LENGTHS)
                ->default('short')
                ->required(),
            TextInput::make('language')
                ->default('English')
                ->datalist(['English', 'Bangla', 'Spanish', 'French', 'German', 'Portuguese', 'Italian', 'Dutch', 'Arabic', 'Hindi'])
                ->required()
                ->maxLength(40),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn (AiPromptTemplate $record): string => str($record->instructions)->limit(120)->toString())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')->label('Writes')->badge()->color('gray'),
                TextColumn::make('tone')->formatStateUsing(fn (string $state): string => AiPromptTemplate::TONES[$state] ?? $state),
                TextColumn::make('length')->formatStateUsing(fn (string $state): string => AiPromptTemplate::LENGTHS[$state] ?? $state),
                TextColumn::make('language'),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('type')->label('Writes')->options(AiContentType::class),
            ])
            ->emptyStateHeading('No AI prompts yet')
            ->emptyStateDescription('A prompt tells the AI what you sell and how to write. Create one here or right from a campaign\'s "AI personalize".')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Content already written with it is kept.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiPromptTemplates::route('/'),
        ];
    }
}
