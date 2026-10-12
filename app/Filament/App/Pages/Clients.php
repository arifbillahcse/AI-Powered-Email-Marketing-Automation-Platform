<?php

namespace App\Filament\App\Pages;

use App\Enums\CampaignStatus;
use App\Exceptions\TeamActionException;
use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agency\AgencyException;
use App\Services\Agency\ClientReport;
use App\Services\Agency\ClientWorkspaces;
use App\Services\Billing\Entitlements;
use App\Support\Timezones;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Agencies: client workspaces with their results, reports and a way in.
 * Owners and admins of an agency (not client) workspace only.
 */
class Clients extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Clients';

    protected static ?string $slug = 'clients';

    /** @var array<int, array{sent: int, replied: int}>|null */
    protected ?array $stats = null;

    public static function canAccess(): bool
    {
        $workspace = Filament::getTenant();
        $user = Auth::user();

        return $workspace instanceof Workspace
            && ! $workspace->isClient()
            && $user instanceof User
            && (bool) $user->roleIn($workspace)?->canManageTeam();
    }

    public static function getNavigationBadge(): ?string
    {
        return modules()->enabled('white_label') ? null : 'Soon';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public function lockReason(): ?string
    {
        if (modules()->disabled('white_label')) {
            return 'disabled';
        }

        return app(Entitlements::class)->allowsModule($this->workspace(), 'white_label') ? null : 'plan';
    }

    public function content(Schema $schema): Schema
    {
        return match ($this->lockReason()) {
            'disabled' => $schema->components([
                EmptyState::make('Agency mode is coming soon')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->iconColor('gray')
                    ->description('Run outreach for your clients from separate client workspaces, under your own brand, with shareable client reports.'),
            ]),
            'plan' => $schema->components([
                EmptyState::make('Client workspaces aren\'t included in your plan')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->iconColor('gray')
                    ->description('Upgrade to a plan with White Label to run outreach for clients under your own brand.'),
            ]),
            default => $schema->components([
                Callout::make('Run outreach for your clients')
                    ->description('Each client gets a separate workspace (leads, mailboxes, campaigns, Unibox) that uses your plan and your branding. Invite a client with read-only access, or share a report link that needs no login.')
                    ->info(),
                EmbeddedTable::make(),
            ]),
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Workspace::query()
                ->where('parent_id', $this->workspace()->getKey())
                ->withCount([
                    'emailAccounts',
                    'campaigns as active_campaigns_count' => fn ($query) => $query->where('status', CampaignStatus::Active->value),
                ]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Client')
                    ->searchable()
                    ->description(fn (Workspace $record): ?string => $record->isSuspended() ? 'Suspended' : null),
                TextColumn::make('email_accounts_count')->label('Mailboxes'),
                TextColumn::make('active_campaigns_count')->label('Active campaigns'),
                TextColumn::make('sent_30d')
                    ->label('Sent (30 days)')
                    ->state(fn (Workspace $record): int => $this->statsFor($record)['sent']),
                TextColumn::make('reply_rate_30d')
                    ->label('Reply rate')
                    ->state(function (Workspace $record): string {
                        ['sent' => $sent, 'replied' => $replied] = $this->statsFor($record);

                        return $sent === 0 ? '—' : round($replied / $sent * 100, 1).'%';
                    }),
                TextColumn::make('report_frequency')
                    ->label('Report email')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ClientReport::FREQUENCIES[$state ?? 'off'] ?? 'Off')
                    ->color(fn (?string $state): string => in_array($state, ['weekly', 'monthly'], true) ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowRightCircle)
                    ->visible(fn (Workspace $record): bool => Auth::user()?->roleIn($record) !== null)
                    ->url(fn (Workspace $record): string => Dashboard::getUrl(tenant: $record)),
                Action::make('viewReport')
                    ->label('Report')
                    ->icon(Heroicon::OutlinedPresentationChartLine)
                    ->color('gray')
                    ->url(fn (Workspace $record): string => app(ClientReport::class)->url($record))
                    ->openUrlInNewTab(),
                Action::make('reportSettings')
                    ->label('Report emails')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->color('gray')
                    ->modalHeading(fn (Workspace $record): string => "Report emails for {$record->name}")
                    ->modalDescription('Headline numbers and a link to the full report, sent to these addresses.')
                    ->fillForm(fn (Workspace $record): array => [
                        'report_frequency' => $record->report_frequency,
                        'report_recipients' => $record->report_recipients ?? [],
                    ])
                    ->schema([
                        Select::make('report_frequency')
                            ->label('Send')
                            ->options(ClientReport::FREQUENCIES)
                            ->required(),
                        TagsInput::make('report_recipients')
                            ->label('Recipients')
                            ->placeholder('client@example.com')
                            ->nestedRecursiveRules(['email:filter', 'max:254'])
                            ->rules(['array', 'max:10'])
                            ->required(fn ($get): bool => $get('report_frequency') !== 'off'),
                    ])
                    ->action(function (Workspace $record, array $data): void {
                        $record->forceFill([
                            'report_frequency' => $data['report_frequency'],
                            'report_recipients' => array_values(array_unique(array_map('strtolower', $data['report_recipients'] ?? []))) ?: null,
                        ])->save();

                        Notification::make()->title('Report emails saved')->success()->send();
                    }),
                Action::make('newReportLink')
                    ->label('New report link')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('The current report link stops working. Share the new one with the client.')
                    ->action(function (Workspace $record): void {
                        app(ClientReport::class)->rotate($record);

                        Notification::make()->title('New report link created')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No clients yet')
            ->emptyStateDescription('Add a client to give them their own workspace.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addClient')
                ->label('Add client')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $this->lockReason() === null)
                ->schema([
                    TextInput::make('name')
                        ->label('Client name')
                        ->placeholder('Acme Inc.')
                        ->required()
                        ->maxLength(100),
                    Select::make('timezone')
                        ->label('Time zone')
                        ->options(Timezones::options())
                        ->searchable()
                        ->default(fn (): string => $this->workspace()->timezone ?: 'UTC')
                        ->required()
                        ->in(array_keys(Timezones::options())),
                    TextInput::make('client_email')
                        ->label('Invite the client (optional)')
                        ->helperText('They get read-only access: results, campaigns and replies, no changes.')
                        ->email()
                        ->maxLength(254),
                ])
                ->action(function (array $data): void {
                    $user = Auth::user();

                    try {
                        $client = app(ClientWorkspaces::class)->create($this->workspace(), $user, $data['name'], $data['timezone'], $data['client_email'] ?? null);
                    } catch (AgencyException $exception) {
                        Notification::make()->title('Client not added')->body($exception->getMessage())->danger()->send();

                        return;
                    } catch (TeamActionException $exception) {
                        Notification::make()->title('Client added, but the invitation wasn\'t sent')->body($exception->getMessage())->warning()->send();

                        return;
                    }

                    Notification::make()
                        ->title("{$client->name} added")
                        ->body('Open the workspace to connect mailboxes and import leads.')
                        ->success()
                        ->actions([Action::make('openClient')->label('Open')->url(Dashboard::getUrl(tenant: $client))])
                        ->send();
                }),
        ];
    }

    /**
     * @return array{sent: int, replied: int}
     */
    protected function statsFor(Workspace $record): array
    {
        if ($this->stats === null) {
            $this->stats = EmailMessage::query()
                ->whereIn('workspace_id', Workspace::query()->select('id')->where('parent_id', $this->workspace()->getKey()))
                ->where('sent_at', '>=', now()->subDays(30))
                ->selectRaw('workspace_id, count(*) as sent, count(replied_at) as replied')
                ->groupBy('workspace_id')
                ->toBase()
                ->get()
                ->mapWithKeys(fn (object $row): array => [(int) $row->workspace_id => ['sent' => (int) $row->sent, 'replied' => (int) $row->replied]])
                ->all();
        }

        return $this->stats[$record->getKey()] ?? ['sent' => 0, 'replied' => 0];
    }

    protected function workspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }
}
