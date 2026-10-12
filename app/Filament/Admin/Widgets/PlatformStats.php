<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class PlatformStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $active = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->with('plan')
            ->get();

        // Monthly recurring revenue, per currency (yearly plans count 1/12).
        $mrr = $active->groupBy(fn (Subscription $subscription): string => $subscription->plan->currency)
            ->map(fn ($subscriptions, string $currency): string => Plan::formatMoney((int) round($subscriptions->sum(
                fn (Subscription $subscription): float => $subscription->interval === BillingInterval::Yearly
                    ? $subscription->plan->price_yearly / 12
                    : $subscription->plan->price_monthly,
            )), $currency))
            ->implode(' + ');

        $revenue = Invoice::query()
            ->where('status', InvoiceStatus::Paid->value)
            ->where('paid_at', '>=', now()->subDays(30))
            ->groupBy('currency')
            ->selectRaw('currency, sum(amount) as total')
            ->get()
            ->map(fn ($row): string => Plan::formatMoney((int) $row->total, $row->currency))
            ->implode(' + ');

        return [
            Stat::make('Workspaces', Number::format(Workspace::query()->count()))
                ->description(Number::format(Workspace::query()->whereNotNull('suspended_at')->count()).' suspended'),
            Stat::make('Users', Number::format(User::query()->count()))
                ->description(Number::format(User::query()->where('created_at', '>=', now()->subDays(30))->count()).' new in 30 days'),
            Stat::make('Paying workspaces', Number::format($active->count()))
                ->description(Number::format(Subscription::query()->where('status', SubscriptionStatus::Trialing->value)->count()).' on trial'),
            Stat::make('MRR', $mrr ?: '0')
                ->description('Revenue, last 30 days: '.($revenue ?: '0')),
        ];
    }
}
