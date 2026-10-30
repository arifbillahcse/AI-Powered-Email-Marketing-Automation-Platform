<?php

namespace App\Filament\App\Resources\Leads;

use App\Enums\EmailVerificationStatus;
use App\Enums\LeadStatus;
use App\Filament\App\Actions\AddLeadNoteAction;
use App\Filament\App\Actions\LeadFollowUpDoneAction;
use App\Filament\App\Actions\SendLeadEmailAction;
use App\Filament\App\Actions\SetLeadFollowUpAction;
use App\Filament\App\Actions\TagLeadAction;
use App\Filament\App\Actions\ToggleLeadStarAction;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Filament\App\Resources\Leads\Pages\CreateLead;
use App\Filament\App\Resources\Leads\Pages\EditLead;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Filament\App\Resources\Leads\Pages\ViewLead;
use App\Filament\App\Resources\Leads\RelationManagers\NotesRelationManager;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\Leads\FollowUps;
use App\Services\Leads\SuppressionList;
use App\Support\Timezones;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class LeadResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'email';

    public static function getNavigationBadge(): ?string
    {
        $workspace = Filament::getTenant();

        if (! $workspace instanceof Workspace) {
            return null;
        }

        $due = app(FollowUps::class)->due($workspace)->count();

        return $due > 0 ? (string) $due : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Follow-ups due';
    }

    protected static function workspaceId(): ?int
    {
        return Filament::getTenant()?->getKey();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contact')
                ->columns(2)
                ->schema([
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('workspace_id', static::workspaceId()),
                        )
                        ->columnSpanFull(),
                    TextInput::make('first_name')->maxLength(100),
                    TextInput::make('last_name')->maxLength(100),
                    TextInput::make('company')->maxLength(255),
                    TextInput::make('title')->label('Job title')->maxLength(255),
                    TextInput::make('phone')->tel()->maxLength(50),
                    TextInput::make('website')->url()->maxLength(255),
                    TextInput::make('linkedin_url')->label('LinkedIn URL')->url()->maxLength(255),
                    TextInput::make('city')->maxLength(255),
                    TextInput::make('country')->maxLength(255),
                    Select::make('timezone')
                        ->label('Time zone')
                        ->helperText('Used for time-zone-aware sending.')
                        ->options(Timezones::options())
                        ->searchable(),
                ]),
            Section::make('Organize')
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->options(LeadStatus::class)
                        ->default(LeadStatus::New->value)
                        ->required(),
                    Select::make('lists')
                        ->relationship('lists', 'name', fn (Builder $query) => $query->where('lead_lists.workspace_id', static::workspaceId()))
                        ->multiple()
                        ->preload(),
                    TagsInput::make('tag_names')
                        ->label('Tags')
                        ->suggestions(fn (): array => Tag::query()->where('workspace_id', static::workspaceId())->orderBy('name')->pluck('name')->all())
                        ->afterStateHydrated(fn (TagsInput $component, ?Lead $record) => $component->state($record?->tags()->pluck('name')->all() ?? []))
                        ->dehydrated(false)
                        ->saveRelationshipsUsing(fn (Lead $record, ?array $state) => $record->syncTagNames($state ?? []))
                        ->columnSpanFull(),
                ]),
            Section::make('Custom fields')
                ->description('Extra data you can use as {{variables}} in emails, e.g. {{company_size}}.')
                ->collapsible()
                ->schema([
                    KeyValue::make('custom_fields')
                        ->hiddenLabel()
                        ->keyLabel('Field')
                        ->valueLabel('Value')
                        ->reorderable(false),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    Section::make('Details')
                        ->columnSpan(2)
                        ->columns(2)
                        ->schema([
                            TextEntry::make('email')->copyable(),
                            TextEntry::make('full_name')->label('Name')->state(fn (Lead $record): string => $record->fullName())->placeholder('—'),
                            TextEntry::make('company')->placeholder('—'),
                            TextEntry::make('title')->label('Job title')->placeholder('—'),
                            TextEntry::make('phone')->placeholder('—'),
                            TextEntry::make('website')->url(fn (Lead $record): ?string => $record->website)->openUrlInNewTab()->placeholder('—'),
                            TextEntry::make('linkedin_url')->label('LinkedIn')->url(fn (Lead $record): ?string => $record->linkedin_url)->openUrlInNewTab()->placeholder('—'),
                            TextEntry::make('location')->state(fn (Lead $record): string => collect([$record->city, $record->country])->filter()->implode(', '))->placeholder('—'),
                            TextEntry::make('timezone')->label('Time zone')->placeholder('—'),
                            TextEntry::make('created_at')->label('Added')->since(),
                        ]),
                    Section::make('Status')
                        ->columnSpan(1)
                        ->schema([
                            TextEntry::make('status')->badge(),
                            TextEntry::make('suppressed')
                                ->label('Suppression')
                                ->state(fn (Lead $record): string => app(SuppressionList::class)->isSuppressed($record->workspace_id, $record->email) ? 'Suppressed (never emailed)' : 'Can be emailed')
                                ->badge()
                                ->color(fn (string $state): string => str_starts_with($state, 'Suppressed') ? 'danger' : 'success'),
                            TextEntry::make('lists.name')->label('Lists')->badge()->placeholder('None'),
                            ViewEntry::make('tags')->label('Tags')->view('filament.app.leads.tags', ['empty' => 'None']),
                            TextEntry::make('follow_up_on')
                                ->label('Follow-up')
                                ->date('M j, Y')
                                ->badge()
                                ->color(fn (Lead $record): string => static::followUpColor($record))
                                ->placeholder('None'),
                            TextEntry::make('latest_note')
                                ->label('Latest note')
                                ->state(fn (Lead $record): ?string => $record->notes()->value('body'))
                                ->limit(160)
                                ->placeholder('No notes yet. Use Add note.'),
                        ]),
                ]),
            Section::make('Custom fields')
                ->columnSpanFull()
                ->visible(fn (Lead $record): bool => filled($record->custom_fields))
                ->schema([
                    ViewEntry::make('custom_fields')->hiddenLabel()->view('filament.app.leads.custom-fields'),
                ]),
            Section::make('Activity')
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('timeline')
                        ->hiddenLabel()
                        ->view('filament.app.leads.timeline'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('tags')
                ->withCount('notes')
                ->addSelect(['latest_note' => LeadNote::query()
                    ->select('body')
                    ->whereColumn('lead_notes.lead_id', 'leads.id')
                    ->latest('id')
                    ->limit(1)])
                ->addSelect(['is_suppressed' => DB::table('suppressions')
                    ->selectRaw('count(*)')
                    ->whereColumn('suppressions.workspace_id', 'leads.workspace_id')
                    ->where(fn ($match) => $match
                        ->where(fn ($q) => $q->where('suppressions.type', 'email')->whereColumn('suppressions.value', 'leads.email'))
                        ->orWhere(fn ($q) => $q->where('suppressions.type', 'domain')->whereColumn('suppressions.value', 'leads.email_domain')))]))
            ->columns([
                IconColumn::make('starred')
                    ->label('')
                    ->boolean()
                    ->trueIcon(Heroicon::Star)
                    ->falseIcon(Heroicon::OutlinedStar)
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn (Lead $record): string => $record->starred ? 'Starred. Click to remove.' : 'Click to star this lead')
                    ->sortable()
                    ->action(function (Lead $record): void {
                        if (! Auth::user()->can('update', $record)) {
                            Notification::make()->title('You can\'t change leads in this workspace')->danger()->send();

                            return;
                        }

                        $record->forceFill(['starred' => ! $record->starred])->save();
                    }),
                TextColumn::make('email')
                    ->label('Lead')
                    ->description(fn (Lead $record): ?string => $record->fullName() ?: null)
                    ->searchable(['email', 'first_name', 'last_name'])
                    ->sortable(),
                TextColumn::make('company')
                    ->description(fn (Lead $record): ?string => $record->title)
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('verification_status')
                    ->label('Email check')
                    ->badge()
                    ->tooltip(fn (Lead $record): ?string => $record->verification_reason)
                    ->placeholder('Not checked')
                    ->toggleable(),
                ViewColumn::make('tags')
                    ->label('Tags')
                    ->view('filament.app.leads.tags')
                    ->viewData(['limit' => 3]),
                TextColumn::make('follow_up_on')
                    ->label('Follow-up')
                    ->badge()
                    ->date('M j')
                    ->color(fn (Lead $record): string => static::followUpColor($record))
                    ->description(fn (Lead $record): ?string => static::followUpOverdue($record) ? 'Overdue' : null)
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('notes_count')
                    ->label('Notes')
                    ->badge()
                    ->color('gray')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->state(fn (Lead $record): ?int => $record->notes_count ?: null)
                    ->tooltip(fn (Lead $record): ?string => $record->latest_note ? str($record->latest_note)->squish()->limit(200)->toString() : null)
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_suppressed')
                    ->label('Suppressed')
                    ->getStateUsing(fn (Lead $record): bool => (bool) $record->is_suppressed)
                    ->icon(fn (bool $state): ?Heroicon => $state ? Heroicon::OutlinedNoSymbol : null)
                    ->color('danger')
                    ->tooltip(fn (bool $state): ?string => $state ? 'On the suppression list: never emailed' : null),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(LeadStatus::class)->multiple(),
                SelectFilter::make('verification_status')
                    ->label('Email check')
                    ->options([...collect(EmailVerificationStatus::cases())->mapWithKeys(fn (EmailVerificationStatus $status): array => [$status->value => $status->getLabel()])->all(), 'none' => 'Not checked'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'none' => $query->whereNull('verification_status'),
                        default => $query->where('verification_status', $data['value']),
                    }),
                SelectFilter::make('lists')
                    ->relationship('lists', 'name', fn (Builder $query) => $query->where('lead_lists.workspace_id', static::workspaceId()))
                    ->multiple()
                    ->preload(),
                SelectFilter::make('tags')
                    ->relationship('tags', 'name', fn (Builder $query) => $query->where('tags.workspace_id', static::workspaceId()))
                    ->multiple()
                    ->preload(),
                SelectFilter::make('segment')
                    ->options(fn (): array => Segment::query()->where('workspace_id', static::workspaceId())->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $segment = filled($data['value'] ?? null)
                            ? Segment::query()->where('workspace_id', static::workspaceId())->find($data['value'])
                            : null;

                        return $segment
                            ? $query->whereIn('leads.id', $segment->leadsQuery()->select('leads.id'))
                            : $query;
                    }),
                TernaryFilter::make('starred')
                    ->label('Starred')
                    ->queries(
                        true: fn (Builder $query) => $query->where('leads.starred', true),
                        false: fn (Builder $query) => $query->where('leads.starred', false),
                    ),
                Filter::make('follow_up_due')
                    ->label('Follow-up due')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('leads.follow_up_on')
                        ->where('leads.follow_up_on', '<=', app(FollowUps::class)->today(Filament::getTenant()))),
                Filter::make('note')
                    ->label('Note contains')
                    ->schema([TextInput::make('note')->label('Note contains')->placeholder('called, no careers page, CEO')])
                    ->query(function (Builder $query, array $data): Builder {
                        $term = trim((string) ($data['note'] ?? ''));

                        if ($term === '') {
                            return $query;
                        }

                        $like = '%'.addcslashes(mb_strtolower($term), '\\%_').'%';

                        return $query->whereExists(fn ($sub) => $sub->selectRaw('1')
                            ->from('lead_notes')
                            ->whereColumn('lead_notes.lead_id', 'leads.id')
                            ->whereRaw('lower(lead_notes.body) like ?', [$like]));
                    })
                    ->indicateUsing(fn (array $data): ?string => filled($data['note'] ?? null) ? 'Note contains "'.$data['note'].'"' : null),
                TernaryFilter::make('suppressed')
                    ->label('Suppressed')
                    ->queries(
                        true: fn (Builder $query) => $query->whereSuppressed(),
                        false: fn (Builder $query) => $query->whereNotSuppressed(),
                    ),
            ])
            ->emptyStateHeading('No leads yet')
            ->emptyStateDescription('Import a CSV or add leads one by one.')
            ->recordActions([
                // One click to write to a lead while scrolling the list.
                SendLeadEmailAction::make()->iconButton()->tooltip('Write email'),
                AddLeadNoteAction::make()->iconButton()->tooltip('Add note'),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    TagLeadAction::make(),
                    ToggleLeadStarAction::make(),
                    SetLeadFollowUpAction::make(),
                    LeadFollowUpDoneAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions(LeadBulkActions::all());
    }

    /**
     * Gray for a future follow-up, amber for today, red when overdue.
     */
    public static function followUpColor(Lead $lead): string
    {
        if ($lead->follow_up_on === null) {
            return 'gray';
        }

        $today = app(FollowUps::class)->today(Filament::getTenant());
        $on = $lead->follow_up_on->toDateString();

        return $on < $today ? 'danger' : ($on === $today ? 'warning' : 'gray');
    }

    public static function followUpOverdue(Lead $lead): bool
    {
        return $lead->follow_up_on !== null && $lead->follow_up_on->toDateString() < app(FollowUps::class)->today(Filament::getTenant());
    }

    public static function getRelations(): array
    {
        return [
            NotesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'create' => CreateLead::route('/create'),
            'view' => ViewLead::route('/{record}'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }
}
