<?php

namespace App\Filament\App\Resources\Campaigns;

use App\Enums\EmailAccountStatus;
use App\Filament\App\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\App\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\App\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\App\Resources\Campaigns\RelationManagers\CampaignLeadsRelationManager;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignMessageBuilder;
use App\Support\Timezones;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use UnitEnum;

class CampaignResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function workspaceId(): ?int
    {
        return Filament::getTenant()?->getKey();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Completed campaigns stay viewable as a record, read-only.
            TextInput::make('name')
                ->label('Campaign name')
                ->required()
                ->maxLength(150)
                ->disabled(fn (?Campaign $record): bool => $record && ! $record->isEditable())
                ->columnSpanFull(),
            Tabs::make()
                ->disabled(fn (?Campaign $record): bool => $record && ! $record->isEditable())
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    static::sequenceTab(),
                    static::audienceTab(),
                    static::sendingTab(),
                    static::optionsTab(),
                ]),
        ]);
    }

    protected static function sequenceTab(): Tab
    {
        $variables = collect(CampaignMessageBuilder::availableVariables())->map(fn (string $v): string => "{{{$v}}}")->implode('  ');

        return Tab::make('Sequence')
            ->icon(Heroicon::OutlinedEnvelope)
            ->schema([
                Callout::make('Personalize every email')
                    ->description("Variables: {$variables}, plus any custom field like {{company_size}}. Add a fallback with {{first_name|there}}. Spintax picks one option per lead: {Hi|Hello|Hey}.")
                    ->info(),
                Repeater::make('steps')
                    ->hiddenLabel()
                    ->relationship()
                    ->orderColumn('position')
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add follow-up email')
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => filled($state['subject'] ?? null)
                        ? (string) $state['subject']
                        : 'Follow-up (same thread)')
                    ->schema([
                        Grid::make(4)->schema([
                            TextInput::make('delay_days')
                                ->label('Wait')
                                ->helperText('Days after the previous email. Ignored for the first one.')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->maxValue(90)
                                ->default(3)
                                ->suffix('days')
                                ->required(),
                            TextInput::make('subject')
                                ->helperText('Leave blank on a follow-up to reply in the same thread.')
                                ->maxLength(255)
                                ->columnSpan(3),
                        ]),
                        // One field for both modes: in plain-text mode the HTML is
                        // converted to text when the email is built.
                        RichEditor::make('body')
                            ->hiddenLabel()
                            ->required()
                            ->helperText(fn (Get $get): ?string => $get('../../plain_text') ? 'Plain-text mode: formatting is removed when sending; links become "text (url)".' : null)
                            ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']]),
                    ]),
            ]);
    }

    protected static function audienceTab(): Tab
    {
        return Tab::make('Audience')
            ->icon(Heroicon::OutlinedUsers)
            ->schema([
                Select::make('leadLists')
                    ->label('Lists')
                    ->relationship('leadLists', 'name', fn (Builder $query) => $query->where('lead_lists.workspace_id', static::workspaceId()))
                    ->multiple()
                    ->preload(),
                Select::make('segments')
                    ->relationship('segments', 'name', fn (Builder $query) => $query->where('segments.workspace_id', static::workspaceId()))
                    ->multiple()
                    ->preload(),
                Text::make(fn (?Campaign $record): string => $record
                    ? Number::format(app(CampaignAudience::class)->count($record)).' leads match right now (suppressed leads are always excluded). Save to update.'
                    : 'Leads in any selected list or segment are included. Suppressed leads are always excluded.'),
            ]);
    }

    protected static function sendingTab(): Tab
    {
        return Tab::make('Sending')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->columns(3)
            ->schema([
                Select::make('emailAccounts')
                    ->label('Send from')
                    ->helperText('Emails rotate across these mailboxes. Each lead keeps the same mailbox for all follow-ups.')
                    ->relationship('emailAccounts', 'email', fn (Builder $query) => $query->where('email_accounts.workspace_id', static::workspaceId()))
                    ->getOptionLabelFromRecordUsing(fn (EmailAccount $record): string => $record->status === EmailAccountStatus::Active
                        ? "{$record->email} ({$record->daily_limit}/day)"
                        : "{$record->email} ({$record->status->getLabel()})")
                    ->multiple()
                    ->preload()
                    ->columnSpanFull(),
                Select::make('timezone')
                    ->label('Time zone')
                    ->options(Timezones::options())
                    ->default(fn (): string => Filament::getTenant()?->timezone ?? 'UTC')
                    ->searchable()
                    ->required(),
                TimePicker::make('send_window_start')
                    ->label('Send from')
                    ->seconds(false)
                    ->format('H:i')
                    ->default('09:00')
                    ->required(),
                TimePicker::make('send_window_end')
                    ->label('Send until')
                    ->seconds(false)
                    ->format('H:i')
                    ->after('send_window_start')
                    ->default('17:00')
                    ->required(),
                CheckboxList::make('send_days')
                    ->label('Send on')
                    ->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'])
                    ->default([1, 2, 3, 4, 5])
                    ->columns(7)
                    ->required()
                    ->columnSpan(2),
                TextInput::make('daily_limit')
                    ->label('Campaign daily limit')
                    ->helperText('Across all mailboxes. Each mailbox\'s own limit also applies.')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(10_000)
                    ->default(100)
                    ->suffix('/ day')
                    ->required(),
            ]);
    }

    protected static function optionsTab(): Tab
    {
        return Tab::make('Options')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->schema([
                Toggle::make('stop_on_reply')
                    ->label('Stop the sequence when a lead replies')
                    ->default(true),
                Toggle::make('track_opens')
                    ->label('Track opens')
                    ->helperText('Adds a tracking pixel. It can hurt deliverability, and many inboxes block it anyway.'),
                Toggle::make('track_clicks')
                    ->label('Track link clicks')
                    ->helperText('Rewrites links through your tracking domain.'),
                Toggle::make('plain_text')
                    ->label('Send as plain text')
                    ->helperText('Plain-text emails look personal and often land in the primary inbox.')
                    ->live(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['steps', 'campaignLeads', 'emailAccounts']))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('steps_count')->label('Emails'),
                TextColumn::make('campaign_leads_count')->label('Leads')->numeric(),
                TextColumn::make('email_accounts_count')->label('Mailboxes'),
                TextColumn::make('launched_at')->label('Launched')->since()->placeholder('—')->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No campaigns yet')
            ->emptyStateDescription('Create a campaign: write a sequence, choose who gets it and which mailboxes send it.')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    CampaignActions::duplicate(),
                    DeleteAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CampaignLeadsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }
}
