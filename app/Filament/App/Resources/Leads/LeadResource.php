<?php

namespace App\Filament\App\Resources\Leads;

use App\Enums\LeadStatus;
use App\Filament\App\Actions\SendLeadEmailAction;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Filament\App\Resources\Leads\Pages\CreateLead;
use App\Filament\App\Resources\Leads\Pages\EditLead;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Filament\App\Resources\Leads\Pages\ViewLead;
use App\Models\Lead;
use App\Models\Segment;
use App\Models\Tag;
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
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                            TextEntry::make('tags.name')->label('Tags')->badge()->color('gray')->placeholder('None'),
                        ]),
                ]),
            Section::make('Custom fields')
                ->columnSpanFull()
                ->visible(fn (Lead $record): bool => filled($record->custom_fields))
                ->schema([
                    KeyValueEntry::make('custom_fields')->hiddenLabel()->keyLabel('Variable')->valueLabel('Value'),
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
                ->addSelect(['is_suppressed' => DB::table('suppressions')
                    ->selectRaw('count(*)')
                    ->whereColumn('suppressions.workspace_id', 'leads.workspace_id')
                    ->where(fn ($match) => $match
                        ->where(fn ($q) => $q->where('suppressions.type', 'email')->whereColumn('suppressions.value', 'leads.email'))
                        ->orWhere(fn ($q) => $q->where('suppressions.type', 'domain')->whereColumn('suppressions.value', 'leads.email_domain')))]))
            ->columns([
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
                TextColumn::make('tags.name')
                    ->label('Tags')
                    ->badge()
                    ->color('gray')
                    ->limitList(3),
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
                ActionGroup::make([
                    SendLeadEmailAction::make(),
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions(LeadBulkActions::all());
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
