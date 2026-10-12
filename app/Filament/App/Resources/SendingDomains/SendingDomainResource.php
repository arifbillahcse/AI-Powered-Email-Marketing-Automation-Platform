<?php

namespace App\Filament\App\Resources\SendingDomains;

use App\Enums\DnsCheckStatus;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Filament\App\Resources\SendingDomains\Pages\CreateSendingDomain;
use App\Filament\App\Resources\SendingDomains\Pages\ListSendingDomains;
use App\Filament\App\Resources\SendingDomains\Pages\ViewSendingDomain;
use App\Models\SendingDomain;
use App\Services\Compliance\BlacklistChecker;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class SendingDomainResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = SendingDomain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Domains';

    protected static ?string $modelLabel = 'domain';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * @var array<string, string>
     */
    protected const CHECK_LABELS = [
        'mx' => 'MX (receiving)',
        'spf' => 'SPF',
        'dkim' => 'DKIM',
        'dmarc' => 'DMARC',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Domain')
                ->placeholder('yourdomain.com')
                ->helperText('Domains are added automatically when you connect a mailbox.')
                ->required()
                ->regex('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i')
                ->validationMessages(['regex' => 'Enter a domain name like yourdomain.com.'])
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('workspace_id', Filament::getTenant()?->getKey()),
                ),
            TextInput::make('dkim_selector')
                ->label('DKIM selector (optional)')
                ->helperText('Only needed if your provider uses a custom selector. Common ones are checked automatically.')
                ->regex('/^[a-z0-9._-]+$/i')
                ->maxLength(63),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Overview')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')->badge(),
                    TextEntry::make('last_checked_at')->label('Last checked')->since()->placeholder('Never'),
                    TextEntry::make('dkim_selector')->label('DKIM selector')->placeholder('Auto-detect'),
                ]),
            ...array_map(static::checkSection(...), array_keys(self::CHECK_LABELS)),
            static::blacklistSection(),
        ]);
    }

    protected static function blacklistSection(): Section
    {
        return Section::make('Blacklists')
            ->description('Checked daily. The domain against domain blocklists, and your mail servers\' IPs against IP blocklists.')
            ->schema([
                Grid::make(4)->schema([
                    static::blacklistStatusEntry(TextEntry::make('blacklist_status')->label('Status')),
                    TextEntry::make('blacklist_checked_at')->label('Last checked')->since()->placeholder('Never'),
                    TextEntry::make('blacklist_ips')
                        ->label('Mail server IPs')
                        ->state(fn (SendingDomain $record): array => $record->blacklist['ips'] ?? [])
                        ->fontFamily(FontFamily::Mono)
                        ->listWithLineBreaks()
                        ->placeholder('None found')
                        ->columnSpan(2),
                ]),
                TextEntry::make('blacklist_listings')
                    ->label('Listed on')
                    ->state(fn (SendingDomain $record): array => collect($record->blacklist['listings'] ?? [])
                        ->map(fn (array $listing): string => "{$listing['list']}: {$listing['target']}")->all())
                    ->listWithLineBreaks()
                    ->color('danger')
                    ->visible(fn (SendingDomain $record): bool => filled($record->blacklist['listings'] ?? null)),
                TextEntry::make('blacklist_help')
                    ->label('How to fix')
                    ->state('Stop sending from this domain, find the cause (an unverified list, a compromised mailbox, too much volume), then request removal on the blacklist\'s website. Most listings expire on their own once the spam stops.')
                    ->visible(fn (SendingDomain $record): bool => filled($record->blacklist['listings'] ?? null)),
                TextEntry::make('blacklist_checked_lists')
                    ->label('Checked')
                    ->state(fn (SendingDomain $record): ?string => filled($record->blacklist['checked'] ?? null)
                        ? implode(', ', $record->blacklist['checked'])
                            .(filled($record->blacklist['unknown'] ?? null) ? '. Couldn\'t check (the list refused the query): '.implode(', ', $record->blacklist['unknown']) : '')
                        : null)
                    ->placeholder('Not checked yet'),
            ]);
    }

    public static function blacklistStatusEntry(TextEntry|TextColumn $component): TextEntry|TextColumn
    {
        return $component
            ->badge()
            ->formatStateUsing(fn (?string $state): string => match ($state) {
                BlacklistChecker::CLEAN => 'Clean',
                BlacklistChecker::LISTED => 'Listed',
                default => 'Not checked',
            })
            ->color(fn (?string $state): string => match ($state) {
                BlacklistChecker::CLEAN => 'success',
                BlacklistChecker::LISTED => 'danger',
                default => 'gray',
            });
    }

    protected static function checkSection(string $check): Section
    {
        $value = fn (SendingDomain $record, string $key): mixed => $record->check($check)[$key] ?? null;

        return Section::make(self::CHECK_LABELS[$check])
            ->schema([
                Grid::make(4)->schema([
                    TextEntry::make("{$check}_status")
                        ->label('Status')
                        ->state(fn (SendingDomain $record): DnsCheckStatus => $record->checkStatus($check))
                        ->badge(),
                    TextEntry::make("{$check}_summary")
                        ->label('Result')
                        ->state(fn (SendingDomain $record): ?string => $value($record, 'summary'))
                        ->placeholder('Not checked yet')
                        ->columnSpan(3),
                ]),
                TextEntry::make("{$check}_records")
                    ->label('Records found')
                    ->state(fn (SendingDomain $record): array => $value($record, 'records') ?? [])
                    ->fontFamily(FontFamily::Mono)
                    ->listWithLineBreaks()
                    ->visible(fn (SendingDomain $record): bool => filled($value($record, 'records'))),
                Grid::make(4)
                    ->visible(fn (SendingDomain $record): bool => filled($value($record, 'fix')))
                    ->schema([
                        TextEntry::make("{$check}_fix_type")
                            ->label('Fix: record type')
                            ->state(fn (SendingDomain $record): ?string => $value($record, 'fix')['type'] ?? null),
                        TextEntry::make("{$check}_fix_host")
                            ->label('Host / name')
                            ->state(fn (SendingDomain $record): ?string => $value($record, 'fix')['host'] ?? null)
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make("{$check}_fix_value")
                            ->label('Value')
                            ->state(fn (SendingDomain $record): ?string => $value($record, 'fix')['value'] ?? null)
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->columnSpan(2),
                    ]),
                TextEntry::make("{$check}_help")
                    ->label('How to fix')
                    ->state(fn (SendingDomain $record): ?string => $value($record, 'help'))
                    ->visible(fn (SendingDomain $record): bool => filled($value($record, 'help'))),
            ]);
    }

    public static function table(Table $table): Table
    {
        $checkColumn = fn (string $check, string $label): TextColumn => TextColumn::make("check_{$check}")
            ->label($label)
            ->state(fn (SendingDomain $record): DnsCheckStatus => $record->checkStatus($check))
            ->badge();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('emailAccounts'))
            ->columns([
                TextColumn::make('name')
                    ->label('Domain')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Overall')
                    ->badge()
                    ->sortable(),
                $checkColumn('mx', 'MX'),
                $checkColumn('spf', 'SPF'),
                $checkColumn('dkim', 'DKIM'),
                $checkColumn('dmarc', 'DMARC'),
                static::blacklistStatusEntry(TextColumn::make('blacklist_status')->label('Blacklists'))
                    ->sortable(),
                TextColumn::make('email_accounts_count')
                    ->label('Mailboxes')
                    ->sortable(),
                TextColumn::make('last_checked_at')
                    ->label('Last checked')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No domains yet')
            ->emptyStateDescription('Domains appear here when you connect a mailbox.')
            ->recordActions([
                SendingDomainActions::checkNow(),
                ViewAction::make(),
                DeleteAction::make()
                    ->visible(fn (SendingDomain $record): bool => $record->email_accounts_count === 0),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSendingDomains::route('/'),
            'create' => CreateSendingDomain::route('/create'),
            'view' => ViewSendingDomain::route('/{record}'),
        ];
    }
}
