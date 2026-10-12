<?php

namespace App\Filament\Admin\Resources\Workspaces;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Admin\Resources\Concerns\SuperAdminOnly;
use App\Filament\Admin\Resources\Workspaces\Pages\ListWorkspaces;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\SubscriptionManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * Every customer workspace: plan, usage, deliverability risk, suspension.
 */
class WorkspaceResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = Workspace::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * 7-day bounce and complaint rates per workspace (one query per page).
     *
     * @var array<int, array{sent: int, bounce_rate: float, complaint_rate: float}>|null
     */
    protected static ?array $risk = null;

    public static function table(Table $table): Table
    {
        static::risk(fresh: true);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('subscription.plan')
                ->withCount(['emailAccounts', 'leads', 'memberships'])
                ->addSelect(['owner_email' => User::query()
                    ->select('users.email')
                    ->join('memberships', 'memberships.user_id', '=', 'users.id')
                    ->whereColumn('memberships.workspace_id', 'workspaces.id')
                    ->where('memberships.role', WorkspaceRole::Owner->value)
                    ->limit(1)]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Workspace $record): ?string => $record->getAttribute('owner_email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subscription.plan.name')->label('Plan')->placeholder('None'),
                TextColumn::make('subscription.status')->label('Billing')->badge()->placeholder('—'),
                TextColumn::make('email_accounts_count')->label('Mailboxes')->sortable(),
                TextColumn::make('leads_count')->label('Leads')->formatStateUsing(fn ($state): string => Number::format((int) $state))->sortable(),
                TextColumn::make('memberships_count')->label('Members'),
                TextColumn::make('risk')
                    ->label('Bounce / complaints (7d)')
                    ->state(function (Workspace $record): ?string {
                        $risk = static::risk()[$record->getKey()] ?? null;

                        return $risk ? "{$risk['bounce_rate']}% / {$risk['complaint_rate']}%" : null;
                    })
                    ->description(fn (Workspace $record): ?string => isset(static::risk()[$record->getKey()]) ? Number::format(static::risk()[$record->getKey()]['sent']).' sent' : null)
                    ->color(fn (Workspace $record): ?string => static::isRisky(static::risk()[$record->getKey()] ?? null) ? 'danger' : null)
                    ->placeholder('No sends'),
                TextColumn::make('suspended_at')
                    ->label('Suspended')
                    ->since()
                    ->description(fn (Workspace $record): ?string => $record->suspension_reason)
                    ->color('danger')
                    ->placeholder('—'),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('billing_status')
                    ->label('Billing')
                    ->options(SubscriptionStatus::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('subscription', fn (Builder $sub) => $sub->where('status', $data['value']))
                        : $query),
                TernaryFilter::make('suspended')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('suspended_at'),
                        false: fn (Builder $query) => $query->whereNull('suspended_at'),
                    ),
                Filter::make('at_risk')
                    ->label('Abuse risk (high bounces or complaints)')
                    ->query(fn (Builder $query): Builder => $query->whereIn('workspaces.id', static::riskyWorkspaceIds())),
            ])
            ->recordActions([
                ActionGroup::make([
                    static::setPlanAction(),
                    static::suspendAction(),
                    Action::make('unsuspend')
                        ->label('Unsuspend')
                        ->icon(Heroicon::OutlinedLockOpen)
                        ->color('success')
                        ->visible(fn (Workspace $record): bool => $record->isSuspended())
                        ->requiresConfirmation()
                        ->action(function (Workspace $record): void {
                            $record->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
                            Notification::make()->title("{$record->name} unsuspended")->success()->send();
                        }),
                ]),
            ]);
    }

    protected static function setPlanAction(): Action
    {
        return Action::make('setPlan')
            ->label('Set plan')
            ->icon(Heroicon::OutlinedRectangleStack)
            ->modalDescription('Changes the subscription directly, without an invoice (e.g. a custom deal or a free extension).')
            ->fillForm(fn (Workspace $record): array => [
                'plan_id' => $record->subscription?->plan_id,
                'interval' => $record->subscription?->interval->value ?? BillingInterval::Monthly->value,
                'status' => $record->subscription?->status->value ?? SubscriptionStatus::Active->value,
                'period_end' => $record->subscription?->current_period_end ?? $record->subscription?->trial_ends_at ?? now()->addMonth(),
            ])
            ->schema([
                Select::make('plan_id')->label('Plan')->options(fn (): array => Plan::query()->orderBy('sort')->pluck('name', 'id')->all())->required(),
                Select::make('interval')->options(BillingInterval::class)->required(),
                Select::make('status')->options(SubscriptionStatus::class)->required(),
                DateTimePicker::make('period_end')->label('Paid until / trial ends')->required(),
            ])
            ->action(function (Workspace $record, array $data): void {
                app(SubscriptionManager::class)->assign(
                    $record,
                    Plan::query()->findOrFail($data['plan_id']),
                    $data['interval'] instanceof BillingInterval ? $data['interval'] : BillingInterval::from($data['interval']),
                    $data['status'] instanceof SubscriptionStatus ? $data['status'] : SubscriptionStatus::from($data['status']),
                    Carbon::parse($data['period_end']),
                );

                Notification::make()->title("Plan updated for {$record->name}")->success()->send();
            });
    }

    protected static function suspendAction(): Action
    {
        return Action::make('suspend')
            ->label('Suspend')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Workspace $record): bool => ! $record->isSuspended())
            ->modalDescription('Stops all sending (campaigns, replies, one-off emails) immediately. The team can still sign in and see the reason.')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason (shown to the customer)')
                    ->default('Unusually high bounce or spam complaint rates.')
                    ->required()
                    ->maxLength(500),
            ])
            ->action(function (Workspace $record, array $data): void {
                $record->forceFill(['suspended_at' => now(), 'suspension_reason' => $data['reason']])->save();
                Notification::make()->title("{$record->name} suspended")->success()->send();
            });
    }

    /**
     * @return array<int, array{sent: int, bounce_rate: float, complaint_rate: float}>
     */
    public static function risk(bool $fresh = false): array
    {
        if ($fresh) {
            static::$risk = null;
        }

        return static::$risk ??= DB::table('email_messages')
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', now()->subDays(7))
            ->groupBy('workspace_id')
            ->selectRaw('workspace_id, count(*) as sent, count(bounced_at) as bounced, count(complained_at) as complained')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->workspace_id => [
                'sent' => (int) $row->sent,
                'bounce_rate' => round($row->bounced / max(1, $row->sent) * 100, 1),
                'complaint_rate' => round($row->complained / max(1, $row->sent) * 100, 2),
            ]])
            ->all();
    }

    /**
     * @param  array{sent: int, bounce_rate: float, complaint_rate: float}|null  $risk
     */
    public static function isRisky(?array $risk): bool
    {
        return $risk !== null
            && $risk['sent'] >= (int) config('outreach.safety.min_sent', 20)
            && ($risk['bounce_rate'] > (float) config('outreach.safety.max_bounce_rate', 5)
                || $risk['complaint_rate'] > (float) config('outreach.safety.max_complaint_rate', 0.3));
    }

    /**
     * @return list<int>
     */
    public static function riskyWorkspaceIds(): array
    {
        return array_keys(array_filter(static::risk(), fn (array $risk): bool => static::isRisky($risk)));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkspaces::route('/'),
        ];
    }
}
