<?php

namespace App\Filament\App\Resources\Campaigns;

use App\Enums\EmailAccountStatus;
use App\Enums\StepType;
use App\Filament\App\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\App\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\App\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\App\Resources\Campaigns\RelationManagers\CampaignLeadsRelationManager;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Models\Campaign;
use App\Models\CampaignStepVariant;
use App\Models\EmailAccount;
use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignMessageBuilder;
use App\Services\Compliance\SpamChecker;
use App\Support\Timezones;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
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
                    ->addActionLabel('Add step')
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => static::stepLabel($state))
                    ->extraItemActions([static::spamCheckAction()])
                    ->schema([
                        Select::make('type')
                            ->label('Step')
                            ->options(fn (): array => static::multichannelAllowed()
                                ? collect(StepType::cases())->mapWithKeys(fn (StepType $type): array => [$type->value => $type->getLabel()])->all()
                                : [StepType::Email->value => StepType::Email->getLabel()])
                            ->helperText(fn (Get $get): ?string => static::isManualStep($get('type'))
                                ? 'A manual step: when a lead gets here, a task appears in Outreach → Tasks and the sequence waits until it\'s done.'
                                : (static::multichannelAllowed() ? null : (modules()->enabled('multichannel') ? 'LinkedIn and call steps aren\'t included in your plan.' : 'LinkedIn and call steps are coming soon.')))
                            ->default(StepType::Email->value)
                            ->selectablePlaceholder(false)
                            // Older form states (and API-made steps) have no type: email.
                            ->dehydrateStateUsing(fn (mixed $state): string => $state instanceof StepType ? $state->value : ((string) $state ?: StepType::Email->value))
                            ->live(),
                        Grid::make(4)->schema([
                            TextInput::make('delay_days')
                                ->label('Wait')
                                ->helperText('Days after the previous step. Ignored for the first one.')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->maxValue(90)
                                ->default(3)
                                ->suffix('days')
                                ->required(),
                            TextInput::make('subject')
                                ->label(fn (Get $get): string => static::isManualStep($get('type')) ? 'Task title' : 'Subject')
                                ->placeholder(fn (Get $get): ?string => static::isManualStep($get('type')) ? 'e.g. Connect with {{first_name}} on LinkedIn' : null)
                                ->helperText(fn (Get $get): string => static::isManualStep($get('type'))
                                    ? 'Optional. Variables like {{first_name}} work here and in the notes.'
                                    : 'Leave blank on a follow-up to reply in the same thread.')
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->columnSpan(3),
                        ]),
                        // One field for both modes: in plain-text mode the HTML is
                        // converted to text when the email is built.
                        RichEditor::make('body')
                            ->hiddenLabel()
                            ->required(fn (Get $get): bool => ! static::isManualStep($get('type')))
                            ->placeholder(fn (Get $get): ?string => static::isManualStep($get('type')) ? 'Notes for whoever does the task: a message to send, a call script...' : null)
                            ->helperText(fn (Get $get): ?string => ! static::isManualStep($get('type')) && $get('../../plain_text') ? 'Plain-text mode: formatting is removed when sending; links become "text (url)".' : null)
                            ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                            ->live(onBlur: true),
                        Text::make(fn (Get $get): string => app(SpamChecker::class)->summary($get('subject'), static::editorHtml($get('body'))))
                            ->color(fn (Get $get): string => app(SpamChecker::class)->check($get('subject'), static::editorHtml($get('body')))['color'])
                            ->visible(fn (Get $get): bool => ! static::isManualStep($get('type'))),
                        static::variantsRepeater()
                            ->visible(fn (Get $get): bool => ! static::isManualStep($get('type'))),
                        Select::make('ab_winner')
                            ->label('A/B winner')
                            ->placeholder('Keep testing')
                            ->helperText('Once set, every lead gets this version. Picked automatically when the test is decided (Options tab).')
                            ->options(fn (Get $get): array => collect(['A', ...collect($get('variants') ?? [])->pluck('label')->filter()->all()])
                                ->mapWithKeys(fn (string $label): array => [$label => "Version {$label}"])->all())
                            ->visible(fn (Get $get): bool => filled($get('variants')) && ! static::isManualStep($get('type'))),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected static function stepLabel(array $state): string
    {
        $type = $state['type'] instanceof StepType ? $state['type'] : StepType::tryFrom((string) ($state['type'] ?? 'email'));

        if ($type !== null && $type->isManual()) {
            return $type->getLabel().(filled($state['subject'] ?? null) ? ': '.$state['subject'] : '');
        }

        return filled($state['subject'] ?? null) ? (string) $state['subject'] : 'Follow-up (same thread)';
    }

    protected static function multichannelAllowed(): bool
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace && app(Entitlements::class)->allowsModule($workspace, 'multichannel');
    }

    protected static function isManualStep(mixed $type): bool
    {
        $type = $type instanceof StepType ? $type : StepType::tryFrom((string) ($type ?? 'email'));

        return (bool) $type?->isManual();
    }

    /**
     * Versions B, C, ... of one sequence email, for A/B/n testing.
     */
    protected static function variantsRepeater(): Repeater
    {
        return Repeater::make('variants')
            ->label('A/B test versions')
            ->helperText('The email above is version A. Each lead gets one version; compare them in "A/B results".')
            ->relationship()
            ->defaultItems(0)
            ->maxItems(count(CampaignStepVariant::LABELS))
            ->addActionLabel('Add a version to test')
            ->collapsible()
            ->collapsed()
            ->itemLabel(fn (array $state): string => 'Version '.($state['label'] ?? 'new').(filled($state['subject'] ?? null) ? ': '.$state['subject'] : ''))
            ->schema([
                // Assigned on save (B, C, ...); kept in state for labels and the winner picker.
                Hidden::make('label')->dehydrated(false),
                TextInput::make('subject')
                    ->placeholder('Same subject as version A')
                    ->maxLength(255),
                RichEditor::make('body')
                    ->hiddenLabel()
                    ->required()
                    ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']]),
            ]);
    }

    /**
     * Every spam issue found in one email of the sequence (its unsaved state).
     */
    protected static function spamCheckAction(): Action
    {
        return Action::make('spamCheck')
            ->label('Spam check')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('gray')
            ->modalHeading('Spam check')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (array $arguments, Repeater $component): HtmlString {
                $item = $component->getRawItemState($arguments['item']);
                $result = app(SpamChecker::class)->check($item['subject'] ?? null, static::editorHtml($item['body'] ?? null));

                $issues = $result['issues'] === []
                    ? '<p>No problems found.</p>'
                    : '<ul style="list-style: disc; padding-inline-start: 1.25rem; display: grid; gap: .375rem;">'
                        .collect($result['issues'])->map(fn (string $issue): string => '<li>'.e($issue).'</li>')->implode('')
                        .'</ul>';

                return new HtmlString('<div style="display: grid; gap: .75rem; font-size: .875rem;">'
                    .'<p><strong>'.e("{$result['score']}/100 · {$result['label']}").'</strong></p>'
                    .$issues
                    .'<p style="opacity: .7;">Spam filters also weigh your domain (SPF, DKIM, DMARC), bounce and complaint rates, and sending volume.</p>'
                    .'</div>');
            });
    }

    /**
     * The editor's live state is a document array until it's saved as HTML.
     */
    public static function editorHtml(mixed $state): string
    {
        if (is_array($state)) {
            return RichContentRenderer::make($state)->toUnsafeHtml();
        }

        return (string) $state;
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
                Toggle::make('verified_only')
                    ->label('Only email verified leads')
                    ->helperText('Skips leads whose address isn\'t verified as valid (Leads → Verify emails). Invalid addresses are always skipped.'),
                Section::make('A/B testing')
                    ->description('Add versions B, C… to any email in the sequence. Each lead gets one version; the winner can be picked automatically.')
                    ->compact()
                    ->columns(3)
                    ->schema([
                        Toggle::make('ab_auto_winner')
                            ->label('Pick the winner automatically')
                            ->default(true)
                            ->columnSpanFull(),
                        Select::make('ab_metric')
                            ->label('Winner by')
                            ->options(['reply' => 'Reply rate', 'open' => 'Open rate (needs open tracking)', 'click' => 'Click rate (needs click tracking)'])
                            ->default('reply')
                            ->required(),
                        TextInput::make('ab_min_sends')
                            ->label('After this many sends per version')
                            ->numeric()
                            ->integer()
                            ->minValue(10)
                            ->maxValue(10_000)
                            ->default(100)
                            ->required(),
                    ]),
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
