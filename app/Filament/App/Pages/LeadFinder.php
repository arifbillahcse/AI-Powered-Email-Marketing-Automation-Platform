<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\Leads\LeadResource;
use App\Jobs\FindLeads;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use App\Services\LeadFinder\LeadFinder as Finder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use UnitEnum;

/**
 * Finds people at the companies you target (by domain) and adds them to a
 * lead list, in the background.
 */
class LeadFinder extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Outreach';

    protected static ?int $navigationSort = 15;

    protected static ?string $title = 'Lead finder';

    protected static ?string $slug = 'lead-finder';

    public static function getNavigationBadge(): ?string
    {
        return modules()->enabled('lead_finder') ? null : 'Soon';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public function lockReason(): ?string
    {
        if (modules()->disabled('lead_finder')) {
            return 'disabled';
        }

        return app(Entitlements::class)->allowsModule($this->workspace(), 'lead_finder') ? null : 'plan';
    }

    public function content(Schema $schema): Schema
    {
        return match ($this->lockReason()) {
            'disabled' => $schema->components([
                EmptyState::make('The lead finder is coming soon')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->iconColor('gray')
                    ->description('Find decision makers and their work emails at the companies you want to reach.'),
            ]),
            'plan' => $schema->components([
                EmptyState::make('The lead finder isn\'t included in your plan')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->iconColor('gray')
                    ->description('Upgrade to find people and work emails at your target companies.'),
            ]),
            default => $schema->components([
                Callout::make(app(Finder::class)->configured() ? 'Find people by company' : 'The lead finder isn\'t set up yet')
                    ->description(app(Finder::class)->configured()
                        ? 'Enter company domains (acme.com). We look up people and their work emails, add them to a list in the background and notify you. Verify the list before sending.'
                        : 'Your administrator needs to add a Hunter.io API key (HUNTER_API_KEY).')
                    ->status(app(Finder::class)->configured() ? 'info' : 'warning'),
                EmbeddedTable::make(),
            ]),
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recently found')
            ->query(fn () => Lead::query()
                ->where('workspace_id', $this->workspace()->getKey())
                ->where('source', 'lead_finder'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('email')
                    ->description(fn (Lead $record): string => $record->fullName())
                    ->url(fn (Lead $record): string => LeadResource::getUrl('view', ['record' => $record]))
                    ->searchable(),
                TextColumn::make('title'),
                TextColumn::make('company')->searchable(),
                TextColumn::make('created_at')->label('Found')->since(),
            ])
            ->paginated([25, 50])
            ->emptyStateHeading('No leads found yet');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('findLeads')
                ->label('Find leads')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->visible(fn (): bool => $this->lockReason() === null && $this->canWrite())
                ->disabled(fn (): bool => ! app(Finder::class)->configured())
                ->schema([
                    Textarea::make('domains')
                        ->label('Company domains')
                        ->placeholder("acme.com\nhttps://www.example.org")
                        ->helperText('One per line, up to '.config('outreach.lead_finder.max_domains').'.')
                        ->rows(6)
                        ->required(),
                    Select::make('department')->options(Finder::DEPARTMENTS)->placeholder('Any department'),
                    Select::make('seniority')->options(Finder::SENIORITIES)->placeholder('Any seniority'),
                    TextInput::make('limit')
                        ->label('People per company')
                        ->numeric()->integer()->minValue(1)->maxValue((int) config('outreach.lead_finder.max_per_domain'))
                        ->default(10)
                        ->required(),
                    Select::make('lead_list_id')
                        ->label('Add to list')
                        ->options(fn (): array => LeadList::query()->where('workspace_id', $this->workspace()->getKey())->orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder('A new list')
                        ->live(),
                    TextInput::make('new_list')
                        ->label('New list name')
                        ->default('Lead finder '.now()->format('M j'))
                        ->visible(fn ($get): bool => blank($get('lead_list_id')))
                        ->required(fn ($get): bool => blank($get('lead_list_id')))
                        ->maxLength(100),
                ])
                ->action(fn (array $data) => $this->find($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function find(array $data): void
    {
        $workspace = $this->workspace();

        if ($reason = app(Entitlements::class)->denies($workspace, 'leads')) {
            Notification::make()->title('Lead limit reached')->body($reason)->danger()->send();

            return;
        }

        $domains = collect(preg_split('/[\s,]+/', (string) $data['domains']) ?: [])
            ->map(fn (string $line): ?string => Finder::normalizeDomain($line))
            ->filter()
            ->unique()
            ->take((int) config('outreach.lead_finder.max_domains'))
            ->values();

        if ($domains->isEmpty()) {
            Notification::make()->title('No valid domains')->body('Enter company domains like acme.com.')->danger()->send();

            return;
        }

        $list = filled($data['lead_list_id'] ?? null)
            ? LeadList::query()->where('workspace_id', $workspace->getKey())->findOrFail($data['lead_list_id'])
            : $this->newList($workspace, (string) $data['new_list']);

        $filters = ['department' => $data['department'] ?? null, 'seniority' => $data['seniority'] ?? null, 'limit' => (int) $data['limit']];
        $workspaceId = (int) $workspace->getKey();
        $listId = (int) $list->getKey();
        $userId = Auth::id();
        $since = now()->subSecond()->toDateTimeString();

        Bus::batch($domains->map(fn (string $domain): FindLeads => new FindLeads($workspaceId, $listId, $domain, $filters))->all())
            ->name('lead-finder')
            ->onQueue('imports')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($workspaceId, $listId, $userId, $since): void {
                $user = $userId ? User::query()->find($userId) : null;

                if (! $user) {
                    return;
                }

                $found = Lead::query()->where('workspace_id', $workspaceId)->where('source', 'lead_finder')->where('created_at', '>=', $since)->count();
                $list = LeadList::query()->find($listId);

                Notification::make()
                    ->title('Lead finder finished')
                    ->body("{$found} new leads added to \"".($list->name ?? 'your list').'". Verify the list before you send.')
                    ->success()
                    ->sendToDatabase($user);
            })
            ->dispatch();

        Notification::make()
            ->title('Searching '.$domains->count().' '.str('company')->plural($domains->count()))
            ->body("New leads go to \"{$list->name}\". You'll get a notification when it's done.")
            ->success()
            ->send();
    }

    protected function newList(Workspace $workspace, string $name): LeadList
    {
        $list = new LeadList(['name' => $name]);
        $list->workspace_id = $workspace->getKey();
        $list->save();

        return $list;
    }

    protected function canWrite(): bool
    {
        $user = Auth::user();

        return $user instanceof User && (bool) $user->roleIn($this->workspace())?->canWrite();
    }

    protected function workspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }
}
