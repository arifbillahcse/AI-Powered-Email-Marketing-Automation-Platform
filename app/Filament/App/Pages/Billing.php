<?php

namespace App\Filament\App\Pages;

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use App\Services\Billing\Gateways\GatewayException;
use App\Services\Billing\Gateways\GatewayManager;
use App\Services\Billing\SubscriptionManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use InvalidArgumentException;
use UnitEnum;

/**
 * The workspace's plan, usage, available plans and invoices. Owners and
 * admins only, and only when billing is on (config/billing.php).
 */
class Billing extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Billing';

    protected static ?string $slug = 'billing';

    public static function canAccess(): bool
    {
        $workspace = Filament::getTenant();
        $user = Auth::user();

        // Client workspaces are covered by their agency's plan.
        return config('billing.enabled')
            && $workspace instanceof Workspace
            && ! $workspace->isClient()
            && $user instanceof User
            && (bool) $user->roleIn($workspace)?->canManageTeam();
    }

    public function mount(): void
    {
        // Back from a payment gateway (see BillingCallbackController).
        $outcome = request()->query('payment');
        $number = (string) request()->query('invoice', '');

        match ($outcome) {
            'paid' => Notification::make()->title('Payment received')->body("Invoice {$number} is paid. Thank you!")->success()->send(),
            'canceled' => Notification::make()->title('Payment canceled')->body('Nothing was charged. You can pay the invoice any time below.')->warning()->send(),
            'failed' => Notification::make()->title('Payment not confirmed')->body('If money left your account, contact us with the invoice number.')->danger()->send(),
            default => null,
        };
    }

    public function content(Schema $schema): Schema
    {
        $workspace = $this->workspace();
        $entitlements = app(Entitlements::class);
        $subscription = $entitlements->subscription($workspace);

        return $schema->components([
            Callout::make(fn (): string => $entitlements->sendingBlockedReason($workspace) ?? '')
                ->warning()
                ->visible(fn (): bool => $entitlements->sendingBlockedReason($workspace) !== null),
            Section::make('Your plan')
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    Text::make($subscription?->plan?->name ?? 'No plan')->size(TextSize::Large)->weight(FontWeight::Bold),
                    Text::make($subscription ? $subscription->status->getLabel() : 'Not subscribed')
                        ->badge()
                        ->color($subscription?->status->getColor() ?? 'gray'),
                    Text::make($this->periodText($subscription)),
                ]),
            Section::make('Usage')
                ->description('Limits of your current plan.')
                ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                ->schema(collect(Plan::LIMITS)->except($entitlements->allowsModule($workspace, 'white_label') ? [] : ['clients'])->map(function (string $label, string $key) use ($entitlements, $workspace): Text {
                    $limit = $entitlements->limit($workspace, $key);
                    $used = $entitlements->usage($workspace, $key);
                    $full = $limit !== null && $used >= $limit;

                    return Text::make("{$label}: ".Number::format($used).' / '.($limit === null ? 'unlimited' : Number::format($limit)))
                        ->color($full ? 'danger' : null);
                })->values()->all()),
            Section::make('Plans')
                ->description('Prices are per workspace. A new plan starts when its invoice is paid.')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema(
                        $this->plans()->map(fn (Plan $plan): Section => Section::make($plan->name)
                            ->description($this->priceText($plan))
                            ->compact()
                            ->schema([
                                Text::make($plan->description ?? '')->visible(filled($plan->description)),
                                ...collect($plan->relevantLimits())->map(fn (string $label, string $key): Text => Text::make(
                                    ($plan->limit($key) === null ? 'Unlimited' : Number::format((int) $plan->limit($key))).' '.strtolower($label)
                                ))->values()->all(),
                                Text::make('Includes: '.collect($plan->modules ?? [])->map(fn (string $module): string => modules()->exists($module) ? modules()->label($module) : $module)->implode(', '))
                                    ->visible(filled($plan->modules)),
                                Text::make('Your current plan')->badge()->color('success')
                                    ->visible($subscription?->plan_id === $plan->getKey() && $subscription->isUsable()),
                            ]))->all(),
                    ),
                ]),
            Section::make('Invoices')->schema([EmbeddedTable::make()]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Invoice::query()->where('workspace_id', $this->workspace()->getKey()))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('Invoice'),
                TextColumn::make('description')->wrap(),
                TextColumn::make('amount')->formatStateUsing(fn (Invoice $record): string => $record->formattedAmount()),
                TextColumn::make('status')->badge(),
                TextColumn::make('due_at')->label('Due')->date(),
                TextColumn::make('paid_at')->label('Paid')->date()->placeholder('—'),
            ])
            ->recordActions([
                $this->payAction(),
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->url(fn (Invoice $record): string => route('billing.invoice', $record))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('No invoices yet');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('choosePlan')
                ->label(fn (): string => $this->subscription()?->status === SubscriptionStatus::Active ? 'Change plan' : 'Choose a plan')
                ->icon(Heroicon::OutlinedSparkles)
                ->modalDescription('You\'ll pay the first invoice right away; the plan starts when it\'s paid.')
                ->schema([
                    Radio::make('plan_id')
                        ->label('Plan')
                        ->options(fn (): array => $this->plans()->mapWithKeys(fn (Plan $plan): array => [$plan->getKey() => $plan->name])->all())
                        ->descriptions(fn (): array => $this->plans()->mapWithKeys(fn (Plan $plan): array => [$plan->getKey() => $this->priceText($plan)])->all())
                        ->default(fn (): ?int => $this->subscription()?->plan_id)
                        ->required(),
                    ToggleButtons::make('interval')
                        ->options(BillingInterval::class)
                        ->default(BillingInterval::Monthly->value)
                        ->inline()
                        ->required(),
                    $this->gatewayField(),
                ])
                ->action(function (array $data): void {
                    $plan = $this->plans()->firstWhere('id', (int) $data['plan_id']);
                    $interval = $data['interval'] instanceof BillingInterval ? $data['interval'] : BillingInterval::from($data['interval']);

                    try {
                        $invoice = app(SubscriptionManager::class)->invoiceFor($this->workspace(), $plan ?? throw new InvalidArgumentException('Choose a plan.'), $interval);
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }

                    $this->startPayment($invoice, (string) $data['gateway']);
                }),
            Action::make('cancelSubscription')
                ->label('Cancel subscription')
                ->color('gray')
                ->visible(fn (): bool => $this->subscription()?->status === SubscriptionStatus::Active && ! $this->subscription()->cancel_at_period_end)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Your plan stays active until '.$this->subscription()?->current_period_end?->toFormattedDateString().', then sending stops. No renewal invoice is created.')
                ->action(function (): void {
                    app(SubscriptionManager::class)->cancel($this->workspace());
                    Notification::make()->title('Subscription canceled')->body('It stays active until the end of the paid period.')->success()->send();
                }),
            Action::make('resumeSubscription')
                ->label('Keep my subscription')
                ->color('gray')
                ->visible(fn (): bool => (bool) $this->subscription()?->cancel_at_period_end && $this->subscription()->isUsable())
                ->action(function (): void {
                    app(SubscriptionManager::class)->resume($this->workspace());
                    Notification::make()->title('Subscription will renew')->success()->send();
                }),
        ];
    }

    protected function payAction(): Action
    {
        return Action::make('pay')
            ->label('Pay')
            ->icon(Heroicon::OutlinedCreditCard)
            ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Open)
            ->modalHeading(fn (Invoice $record): string => "Pay {$record->number} ({$record->formattedAmount()})")
            ->schema([$this->gatewayField()])
            ->action(fn (Invoice $record, array $data) => $this->startPayment($record, (string) $data['gateway']));
    }

    protected function gatewayField(): Radio
    {
        return Radio::make('gateway')
            ->label('Pay with')
            ->options(fn (): array => app(GatewayManager::class)->options())
            ->default(fn (): ?string => array_key_first(app(GatewayManager::class)->options()))
            ->helperText('bKash and SSLCommerz charge in BDT at our current exchange rate.')
            ->required();
    }

    protected function startPayment(Invoice $invoice, string $gatewayKey): void
    {
        $gateway = app(GatewayManager::class)->available()[$gatewayKey] ?? null;

        if (! $gateway) {
            Notification::make()->title('That payment method isn\'t available.')->danger()->send();

            return;
        }

        try {
            $start = $gateway->start($invoice, Auth::user());
        } catch (GatewayException|InvalidArgumentException $exception) {
            Notification::make()->title('Payment couldn\'t be started')->body($exception->getMessage())->danger()->send();

            return;
        }

        if ($start->redirectUrl) {
            $this->redirect($start->redirectUrl);

            return;
        }

        Notification::make()
            ->title("Invoice {$invoice->number}: {$invoice->formattedAmount()}")
            ->body($start->instructions)
            ->persistent()
            ->info()
            ->send();
    }

    protected function periodText(?Subscription $subscription): string
    {
        return match (true) {
            $subscription === null => 'Choose a plan to start sending.',
            $subscription->status === SubscriptionStatus::Trialing => 'Free trial until '.$subscription->trial_ends_at?->toFormattedDateString().'.',
            $subscription->status === SubscriptionStatus::Expired => 'Expired. Choose a plan or pay the open invoice.',
            $subscription->cancel_at_period_end => 'Canceled: active until '.$subscription->current_period_end?->toFormattedDateString().'.',
            $subscription->status === SubscriptionStatus::PastDue => 'Payment overdue since '.$subscription->current_period_end?->toFormattedDateString().'.',
            default => ucfirst($subscription->interval->getLabel()).', renews on '.$subscription->current_period_end?->toFormattedDateString().'.',
        };
    }

    protected function priceText(Plan $plan): string
    {
        return collect([
            $plan->price_monthly > 0 ? Plan::formatMoney($plan->price_monthly, $plan->currency).' / month' : null,
            $plan->price_yearly > 0 ? Plan::formatMoney($plan->price_yearly, $plan->currency).' / year' : null,
        ])->filter()->implode(' · ') ?: 'Free';
    }

    /**
     * @return Collection<int, Plan>
     */
    protected function plans(): Collection
    {
        return Plan::query()->where('is_active', true)->orderBy('sort')->orderBy('price_monthly')->get();
    }

    protected function subscription(): ?Subscription
    {
        return app(Entitlements::class)->subscription($this->workspace());
    }

    protected function workspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }
}
