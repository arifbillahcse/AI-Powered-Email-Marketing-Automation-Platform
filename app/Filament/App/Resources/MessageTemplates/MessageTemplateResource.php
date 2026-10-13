<?php

namespace App\Filament\App\Resources\MessageTemplates;

use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Filament\App\Resources\MessageTemplates\Pages\ManageMessageTemplates;
use App\Models\MessageTemplate;
use App\Services\Inbox\InboxReplier;
use BackedEnum;
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

/**
 * Saved one-off emails for the lead's "Write email" composer.
 */
class MessageTemplateResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = MessageTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 27;

    protected static ?string $navigationLabel = 'Message templates';

    protected static ?string $modelLabel = 'message template';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'message-templates';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->placeholder('Intro: web development')
                ->helperText('Only you and your team see it.')
                ->required()
                ->maxLength(100)
                ->unique(
                    table: 'message_templates',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('workspace_id', Filament::getTenant()?->getKey()),
                ),
            TextInput::make('subject')
                ->placeholder('Quick idea for {{company|your website}}')
                ->maxLength(250),
            Textarea::make('body')
                ->label('Message')
                ->placeholder("Hi {{first_name|there}},\n\nI came across {{company|your company}} and...")
                ->helperText('Plain text. Variables are filled in for each lead: {{first_name|there}}, {{company}}, {{title}}, {{website}}, {{sender_first_name}}. Your mailbox signature is added when sending.')
                ->rows(10)
                ->required()
                ->maxLength(InboxReplier::MAX_LENGTH)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn (MessageTemplate $record): string => str($record->body)->squish()->limit(110)->toString())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject')->placeholder('—')->limit(50),
                TextColumn::make('times_used')->label('Used')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No message templates yet')
            ->emptyStateDescription('Save the short emails you send often, then pick one in a lead\'s "Write email" and change a line to make it personal.')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMessageTemplates::route('/'),
        ];
    }
}
