<?php

namespace App\Filament\App\Resources\EmailAccounts;

use App\Enums\EmailAccountStatus;
use App\Enums\MailEncryption;
use App\Enums\MailProvider;
use App\Filament\App\Resources\Concerns\ScopedToWorkspace;
use App\Filament\App\Resources\EmailAccounts\Pages\CreateEmailAccount;
use App\Filament\App\Resources\EmailAccounts\Pages\EditEmailAccount;
use App\Filament\App\Resources\EmailAccounts\Pages\ListEmailAccounts;
use App\Models\EmailAccount;
use App\Services\Dns\TrackingDomainVerifier;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class EmailAccountResource extends Resource
{
    use ScopedToWorkspace;

    protected static ?string $model = EmailAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Email accounts';

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    static::accountTab(),
                    static::serversTab(),
                    static::sendingTab(),
                    static::signatureTab(),
                    static::trackingTab(),
                    static::warmupTab(),
                ]),
        ]);
    }

    protected static function accountTab(): Tab
    {
        return Tab::make('Account')
            ->icon(Heroicon::OutlinedUser)
            ->columns(2)
            ->schema([
                Select::make('provider')
                    ->options(MailProvider::class)
                    ->default(MailProvider::Google->value)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        foreach (MailProvider::fromState($state)?->preset() ?? [] as $field => $value) {
                            $set($field, $value);
                        }
                    })
                    ->columnSpanFull(),
                Callout::make('Before you connect')
                    ->description(fn (Get $get): ?string => MailProvider::fromState($get('provider'))?->setupHint())
                    ->visible(fn (Get $get): bool => filled(MailProvider::fromState($get('provider'))?->setupHint()))
                    ->info()
                    ->columnSpanFull(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('workspace_id', Filament::getTenant()?->getKey()),
                    ),
                TextInput::make('from_name')
                    ->label('Sender name')
                    ->helperText('What recipients see, e.g. "Arif from Softorio".')
                    ->required()
                    ->maxLength(100),
            ]);
    }

    protected static function serversTab(): Tab
    {
        $passwordField = fn (string $name, bool $requiredOnCreate) => TextInput::make($name)
            ->label('Password')
            ->password()
            ->revealable()
            ->formatStateUsing(fn (): ?string => null)
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->required(fn (string $operation): bool => $requiredOnCreate && $operation === 'create')
            ->maxLength(500)
            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leave blank to keep the saved password.' : null);

        return Tab::make('Servers')
            ->icon(Heroicon::OutlinedServerStack)
            ->schema([
                Grid::make(3)->schema([
                    TextInput::make('smtp_host')
                        ->label('SMTP host')
                        ->default(MailProvider::Google->preset()['smtp_host'])
                        ->required()
                        ->maxLength(255),
                    TextInput::make('smtp_port')
                        ->label('SMTP port')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(65535)
                        ->default(MailProvider::Google->preset()['smtp_port'])
                        ->required(),
                    Select::make('smtp_encryption')
                        ->label('SMTP encryption')
                        ->options(MailEncryption::class)
                        ->default(MailProvider::Google->preset()['smtp_encryption'])
                        ->required(),
                    TextInput::make('smtp_username')
                        ->label('Username')
                        ->placeholder('Same as email address')
                        ->maxLength(255),
                    $passwordField('smtp_password', true),
                ]),
                Grid::make(3)->schema([
                    TextInput::make('imap_host')
                        ->label('IMAP host')
                        ->default(MailProvider::Google->preset()['imap_host'])
                        ->required()
                        ->maxLength(255),
                    TextInput::make('imap_port')
                        ->label('IMAP port')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(65535)
                        ->default(MailProvider::Google->preset()['imap_port'])
                        ->required(),
                    Select::make('imap_encryption')
                        ->label('IMAP encryption')
                        ->options(MailEncryption::class)
                        ->default(MailProvider::Google->preset()['imap_encryption'])
                        ->required(),
                    TextInput::make('imap_username')
                        ->label('Username')
                        ->placeholder('Same as SMTP')
                        ->maxLength(255),
                    $passwordField('imap_password', false)
                        ->placeholder('Same as SMTP'),
                ]),
            ]);
    }

    protected static function sendingTab(): Tab
    {
        $limits = config('outreach.mailboxes');

        return Tab::make('Sending limits')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->columns(3)
            ->schema([
                TextInput::make('daily_limit')
                    ->label('Daily limit')
                    ->helperText('Emails per day. Keep new mailboxes at 30–50 for cold outreach.')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue($limits['max_daily_limit'])
                    ->default($limits['default_daily_limit'])
                    ->suffix('/ day')
                    ->required(),
                TextInput::make('min_delay_seconds')
                    ->label('Minimum gap')
                    ->numeric()
                    ->integer()
                    ->minValue($limits['min_delay_floor_seconds'])
                    ->default($limits['default_min_delay_seconds'])
                    ->suffix('sec')
                    ->required(),
                TextInput::make('max_delay_seconds')
                    ->label('Maximum gap')
                    ->helperText('A random gap in this range is used between emails.')
                    ->numeric()
                    ->integer()
                    ->gte('min_delay_seconds')
                    ->default($limits['default_max_delay_seconds'])
                    ->suffix('sec')
                    ->required(),
                TimePicker::make('send_window_start')
                    ->label('Send from')
                    ->seconds(false)
                    ->format('H:i')
                    ->default($limits['default_send_window'][0])
                    ->required(),
                TimePicker::make('send_window_end')
                    ->label('Send until')
                    ->helperText('In the workspace time zone.')
                    ->seconds(false)
                    ->format('H:i')
                    ->after('send_window_start')
                    ->default($limits['default_send_window'][1])
                    ->required(),
                CheckboxList::make('send_days')
                    ->label('Send on')
                    ->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'])
                    ->default($limits['default_send_days'])
                    ->columns(7)
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    protected static function signatureTab(): Tab
    {
        return Tab::make('Signature')
            ->icon(Heroicon::OutlinedPencil)
            ->schema([
                RichEditor::make('signature')
                    ->helperText('Added to the end of every email from this mailbox.')
                    ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList'], ['undo', 'redo']]),
            ]);
    }

    protected static function trackingTab(): Tab
    {
        return Tab::make('Tracking domain')
            ->icon(Heroicon::OutlinedLink)
            ->schema([
                Callout::make('Why use a tracking domain?')
                    ->description(fn (): string => 'Open and click tracking links use a shared domain by default. Using your own subdomain improves deliverability. Create a CNAME record pointing your subdomain to '.app(TrackingDomainVerifier::class)->target().', enter it below, then click "Verify tracking domain".')
                    ->info(),
                TextInput::make('tracking_domain')
                    ->label('Tracking subdomain')
                    ->placeholder('track.yourdomain.com')
                    ->regex('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i')
                    ->validationMessages(['regex' => 'Enter a domain name like track.yourdomain.com.'])
                    ->maxLength(253),
            ]);
    }

    protected static function warmupTab(): Tab
    {
        $locked = fn (): bool => modules()->disabled('warmup');

        return Tab::make('Warmup')
            ->icon(Heroicon::OutlinedFire)
            ->badge(fn (): ?string => modules()->disabled('warmup') ? 'Soon' : null)
            ->badgeColor('gray')
            ->schema([
                Callout::make('Inbox warmup is coming soon')
                    ->description('Warmup builds sender reputation by exchanging real conversations with other inboxes. These settings unlock when the module is enabled.')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->visible($locked),
                Grid::make(3)->schema([
                    Toggle::make('warmup_enabled')
                        ->label('Enable warmup'),
                    TextInput::make('warmup_daily_target')
                        ->label('Warmup emails / day')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(100),
                    TextInput::make('warmup_reply_rate')
                        ->label('Reply rate')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%'),
                ])->disabled($locked),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sendingDomain'))
            ->columns([
                TextColumn::make('email')
                    ->description(fn (EmailAccount $record): string => $record->from_name)
                    ->searchable(['email', 'from_name'])
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->tooltip(fn (EmailAccount $record): ?string => $record->last_error)
                    ->sortable(),
                TextColumn::make('daily_limit')
                    ->label('Daily limit')
                    ->suffix(' / day')
                    ->sortable(),
                TextColumn::make('sendingDomain.status')
                    ->label('Domain health')
                    ->badge(),
                IconColumn::make('tracking_domain_verified_at')
                    ->label('Tracking domain')
                    ->boolean()
                    ->getStateUsing(fn (EmailAccount $record): ?bool => filled($record->tracking_domain) ? $record->tracking_domain_verified_at !== null : null),
                TextColumn::make('last_tested_at')
                    ->label('Last tested')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->defaultSort('email')
            ->filters([
                SelectFilter::make('status')->options(EmailAccountStatus::class),
            ])
            ->emptyStateHeading('No email accounts yet')
            ->emptyStateDescription('Connect the mailboxes your campaigns will send from.')
            ->recordActions([
                ActionGroup::make([
                    EmailAccountActions::testConnection(),
                    EmailAccountActions::sendTestEmail(),
                    EmailAccountActions::verifyTrackingDomain(),
                    EmailAccountActions::pause(),
                    EmailAccountActions::resume(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailAccounts::route('/'),
            'create' => CreateEmailAccount::route('/create'),
            'edit' => EditEmailAccount::route('/{record}/edit'),
        ];
    }
}
