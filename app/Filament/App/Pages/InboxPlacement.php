<?php

namespace App\Filament\App\Pages;

use App\Enums\EmailAccountStatus;
use App\Models\EmailAccount;
use App\Models\PlacementResult;
use App\Models\PlacementTest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use App\Services\Placement\PlacementException;
use App\Services\Placement\PlacementTester;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Inbox placement tests: where a mailbox's email lands (inbox or spam) at
 * Gmail, Outlook, Yahoo and others, before a campaign goes out.
 */
class InboxPlacement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 35;

    protected static ?string $title = 'Inbox placement';

    protected static ?string $slug = 'inbox-placement';

    public static function getNavigationBadge(): ?string
    {
        return modules()->enabled('inbox_placement') ? null : 'Soon';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public function lockReason(): ?string
    {
        if (modules()->disabled('inbox_placement')) {
            return 'disabled';
        }

        return app(Entitlements::class)->allowsModule($this->workspace(), 'inbox_placement') ? null : 'plan';
    }

    public function content(Schema $schema): Schema
    {
        return match ($this->lockReason()) {
            'disabled' => $schema->components([
                EmptyState::make('Inbox placement tests are coming soon')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->iconColor('gray')
                    ->description('See whether your emails reach the inbox or spam at Gmail, Outlook and others before you launch.'),
            ]),
            'plan' => $schema->components([
                EmptyState::make('Inbox placement tests aren\'t included in your plan')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->iconColor('gray')
                    ->description('Upgrade to see where your emails land before a campaign goes out.'),
            ]),
            default => $schema->components([
                Callout::make('How it works')
                    ->description('We send your email from the mailbox you choose to seed inboxes at different providers, then check where each one landed. Results arrive within '.config('outreach.placement.wait_minutes').' minutes; you get a notification. Test with the real subject and body of your campaign.')
                    ->info(),
                EmbeddedTable::make(),
            ]),
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => PlacementTest::query()
                ->where('workspace_id', $this->workspace()->getKey())
                ->with('emailAccount'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Started')->since(),
                TextColumn::make('emailAccount.email')->label('Mailbox'),
                TextColumn::make('subject')->limit(50),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sending' => 'Sending',
                        'checking' => 'Checking',
                        'done' => 'Done',
                        default => 'Failed',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'done' => 'success',
                        'failed' => 'danger',
                        default => 'info',
                    })
                    ->description(fn (PlacementTest $record): ?string => $record->error),
                TextColumn::make('inbox_rate')
                    ->label('Inbox')
                    ->badge()
                    ->state(function (PlacementTest $record): string {
                        $summary = app(PlacementTester::class)->summary($record);

                        return $summary['rate'] === null ? '—' : "{$summary['rate']}%";
                    })
                    ->color(function (PlacementTest $record): string {
                        $rate = app(PlacementTester::class)->summary($record)['rate'];

                        return match (true) {
                            $rate === null => 'gray',
                            $rate >= 90 => 'success',
                            $rate >= 70 => 'warning',
                            default => 'danger',
                        };
                    })
                    ->description(function (PlacementTest $record): string {
                        $summary = app(PlacementTester::class)->summary($record);

                        return "{$summary['inbox']} inbox · {$summary['spam']} spam · {$summary['missing']} missing".($summary['pending'] > 0 ? " · {$summary['pending']} waiting" : '');
                    }),
            ])
            ->recordActions([
                Action::make('results')
                    ->label('Results')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->color('gray')
                    ->modalHeading(fn (PlacementTest $record): string => "Results: {$record->subject}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (PlacementTest $record): array => $this->resultsSchema($record)),
            ])
            ->emptyStateHeading('No tests yet')
            ->emptyStateDescription('Run a test before launching a campaign or after changing DNS.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTest')
                ->label('New test')
                ->icon(Heroicon::OutlinedPlay)
                ->visible(fn (): bool => $this->lockReason() === null && $this->canWrite())
                ->schema([
                    Select::make('email_account_id')
                        ->label('Send from')
                        ->options(fn (): array => EmailAccount::query()
                            ->where('workspace_id', $this->workspace()->getKey())
                            ->where('status', EmailAccountStatus::Active->value)
                            ->orderBy('email')
                            ->pluck('email', 'id')
                            ->all())
                        ->required(),
                    TextInput::make('subject')->required()->maxLength(255),
                    Textarea::make('body')
                        ->helperText('Paste the email as your leads will read it (plain text). Links count: spam filters look at them too.')
                        ->rows(8)
                        ->required()
                        ->maxLength(20000),
                    Toggle::make('include_own')
                        ->label('Also send to my other mailboxes')
                        ->helperText('Useful when they are at other providers.')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    $sender = EmailAccount::query()
                        ->where('workspace_id', $this->workspace()->getKey())
                        ->find($data['email_account_id']);

                    try {
                        $test = app(PlacementTester::class)->start(
                            $this->workspace(),
                            $sender ?? throw new PlacementException('Choose a mailbox.'),
                            Auth::user(),
                            (string) $data['subject'],
                            (string) $data['body'],
                            (bool) ($data['include_own'] ?? false),
                        );
                    } catch (PlacementException $exception) {
                        Notification::make()->title('Test not started')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Test started')
                        ->body("Sending to {$test->results()->count()} seed inboxes. Results within ".config('outreach.placement.wait_minutes').' minutes.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @return list<Text>
     */
    protected function resultsSchema(PlacementTest $test): array
    {
        $results = PlacementResult::query()->where('placement_test_id', $test->getKey())->with('seed')->orderBy('provider')->get();

        if ($results->isEmpty()) {
            return [Text::make('No results.')];
        }

        return $results->map(function (PlacementResult $result) use ($test): Text {
            // Platform seed addresses stay private; your own mailboxes are shown.
            $address = $result->seed?->workspace_id === $test->workspace_id ? $result->seed->email : Str::mask((string) $result->seed?->email, '*', 2, 6);

            return Text::make("{$result->provider} ({$address}): ".ucfirst($result->status))
                ->color(PlacementResult::color($result->status));
        })->all();
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
