<?php

namespace App\Filament\App\Pages;

use App\Models\EmailAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use App\Services\Warmup\WarmupHealth;
use App\Services\Warmup\WarmupPlanner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Inbox warmup: which mailboxes warm up, today's ramp-up volume and each
 * mailbox's inbox placement (health) over the last 7 days.
 */
class Warmup extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Warmup';

    protected static ?string $slug = 'warmup';

    /** @var array<int, array{sent: int, delivered: int, spam: int, replies: int, score: ?int}>|null */
    protected ?array $health = null;

    public static function getNavigationBadge(): ?string
    {
        return modules()->enabled('warmup') ? null : 'Soon';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public function isLocked(): bool
    {
        return $this->lockReason() !== null;
    }

    public function lockReason(): ?string
    {
        if (modules()->disabled('warmup')) {
            return 'disabled';
        }

        return app(Entitlements::class)->allowsModule($this->workspace(), 'warmup') ? null : 'plan';
    }

    public function content(Schema $schema): Schema
    {
        return match ($this->lockReason()) {
            'disabled' => $schema->components([
                EmptyState::make('Inbox warmup is coming soon')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->iconColor('gray')
                    ->description('Warmup gradually builds your mailboxes\' sender reputation by exchanging real conversations with other inboxes. It will switch on here automatically once the module is enabled.'),
            ]),
            'plan' => $schema->components([
                EmptyState::make('Warmup isn\'t included in your plan')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->iconColor('gray')
                    ->description('Upgrade to a plan with Inbox Warmup to build your mailboxes\' sender reputation before and while you send campaigns.'),
            ]),
            default => $schema->components([
                Callout::make('How warmup works')
                    ->description('Warmup mailboxes email each other short, natural conversations. We open them, rescue them from spam, file them in a "Warmup" folder and reply to some, which teaches mailbox providers that your emails are wanted. Volume starts at '.config('outreach.warmup.start_per_day').' a day and grows by '.config('outreach.warmup.increase_per_day').' a day up to each mailbox\'s target. Keep warmup on while you run campaigns.')
                    ->info(),
                Section::make('Last 7 days')
                    ->columns(['default' => 2, 'lg' => 4])
                    ->schema($this->summary()),
                EmbeddedTable::make(),
            ]),
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => EmailAccount::query()->where('workspace_id', $this->workspace()->getKey()))
            ->defaultSort('email')
            ->columns([
                TextColumn::make('email')
                    ->label('Mailbox')
                    ->description(fn (EmailAccount $record): string => $record->from_name)
                    ->searchable(),
                TextColumn::make('warmup_state')
                    ->label('Warmup')
                    ->badge()
                    ->state(fn (EmailAccount $record): string => match (true) {
                        ! $record->warmup_enabled => 'Off',
                        ! $record->isActive() => 'Waiting (mailbox '.strtolower($record->status->getLabel()).')',
                        $record->warmup_error !== null => 'Needs attention',
                        default => 'Day '.app(WarmupPlanner::class)->day($record),
                    })
                    ->color(fn (EmailAccount $record): string => match (true) {
                        ! $record->warmup_enabled => 'gray',
                        ! $record->isActive() || $record->warmup_error !== null => 'warning',
                        default => 'success',
                    })
                    ->description(fn (EmailAccount $record): ?string => $record->warmup_enabled ? $record->warmup_error : null),
                TextColumn::make('warmup_today')
                    ->label('Today')
                    ->state(function (EmailAccount $record): string {
                        if (! $record->warmup_enabled) {
                            return '—';
                        }

                        $planner = app(WarmupPlanner::class);

                        return $planner->startedToday($record).' / '.$planner->quota($record);
                    })
                    ->tooltip(fn (EmailAccount $record): string => "Target: {$record->warmup_daily_target} a day, reply rate {$record->warmup_reply_rate}%"),
                TextColumn::make('warmup_sent')
                    ->label('Sent')
                    ->state(fn (EmailAccount $record): int => $this->healthFor($record)['sent']),
                TextColumn::make('warmup_score')
                    ->label('Inbox rate')
                    ->badge()
                    ->state(fn (EmailAccount $record): string => ($score = $this->healthFor($record)['score']) === null ? 'No data' : "{$score}%")
                    ->color(fn (EmailAccount $record): string => WarmupHealth::color($this->healthFor($record)['score']))
                    ->tooltip('Share of delivered warmup emails that landed in the inbox, not spam (last 7 days).'),
                TextColumn::make('warmup_spam')
                    ->label('Saved from spam')
                    ->state(fn (EmailAccount $record): int => $this->healthFor($record)['spam']),
                TextColumn::make('warmup_replies')
                    ->label('Replies')
                    ->state(fn (EmailAccount $record): int => $this->healthFor($record)['replies']),
            ])
            ->recordActions([
                Action::make('warmupSettings')
                    ->label('Settings')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->color('gray')
                    ->visible(fn (): bool => $this->canWrite())
                    ->modalHeading(fn (EmailAccount $record): string => "Warmup for {$record->email}")
                    ->fillForm(fn (EmailAccount $record): array => [
                        'warmup_enabled' => $record->warmup_enabled,
                        'warmup_daily_target' => $record->warmup_daily_target,
                        'warmup_reply_rate' => $record->warmup_reply_rate,
                    ])
                    ->schema([
                        Toggle::make('warmup_enabled')->label('Warm up this mailbox'),
                        TextInput::make('warmup_daily_target')
                            ->label('Target emails / day')
                            ->helperText('Reached gradually. 20–40 is plenty for most mailboxes.')
                            ->numeric()->integer()->minValue(1)->maxValue((int) config('outreach.warmup.max_daily_target'))->required(),
                        TextInput::make('warmup_reply_rate')
                            ->label('Reply rate')
                            ->helperText('How often received warmup emails are answered.')
                            ->numeric()->integer()->minValue(0)->maxValue(100)->suffix('%')->required(),
                    ])
                    ->action(function (EmailAccount $record, array $data): void {
                        $record->fill([
                            'warmup_enabled' => (bool) $data['warmup_enabled'],
                            'warmup_daily_target' => (int) $data['warmup_daily_target'],
                            'warmup_reply_rate' => (int) $data['warmup_reply_rate'],
                        ])->save();

                        Notification::make()->title('Warmup settings saved')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No mailboxes yet')
            ->emptyStateDescription('Connect a mailbox under Email accounts, then switch warmup on here.');
    }

    /**
     * @return list<Text>
     */
    protected function summary(): array
    {
        $ids = EmailAccount::query()->where('workspace_id', $this->workspace()->getKey())->pluck('id')->all();
        $health = collect(app(WarmupHealth::class)->forMailboxes($ids));
        $delivered = $health->sum('delivered');
        $spam = $health->sum('spam');
        $score = $delivered > 0 ? (int) round(($delivered - $spam) / $delivered * 100) : null;
        $warming = EmailAccount::query()->where('workspace_id', $this->workspace()->getKey())->where('warmup_enabled', true)->count();

        $stat = fn (string $label, string $value, ?string $color = null): Text => Text::make("{$label}: {$value}")
            ->size(TextSize::Large)
            ->weight(FontWeight::SemiBold)
            ->color($color);

        return [
            $stat('Warming up', $warming.' '.str('mailbox')->plural($warming)),
            $stat('Warmup emails sent', (string) $health->sum('sent')),
            $stat('Inbox rate', $score === null ? 'No data yet' : "{$score}%", $score === null ? null : WarmupHealth::color($score)),
            $stat('Saved from spam', (string) $spam),
        ];
    }

    /**
     * @return array{sent: int, delivered: int, spam: int, replies: int, score: ?int}
     */
    protected function healthFor(EmailAccount $record): array
    {
        $this->health ??= app(WarmupHealth::class)->forMailboxes(
            EmailAccount::query()->where('workspace_id', $this->workspace()->getKey())->pluck('id')->all(),
        );

        return $this->health[$record->getKey()] ?? ['sent' => 0, 'delivered' => 0, 'spam' => 0, 'replies' => 0, 'score' => null];
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
