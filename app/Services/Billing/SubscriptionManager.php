<?php

namespace App\Services\Billing;

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\App\Pages\Billing;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Compliance\WorkspaceAlerts;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The subscription lifecycle:
 *
 *   new workspace ─▶ trialing ─(trial ends unpaid)─▶ expired
 *   invoice paid ─▶ active ─(period ends unpaid)─▶ past_due ─(grace)─▶ expired
 *
 * Every period is an invoice. Paying it activates or extends the
 * subscription; renewal invoices are created a few days before a period
 * ends. Plan changes take effect when the new plan's invoice is paid.
 */
class SubscriptionManager
{
    public function __construct(
        protected WorkspaceAlerts $alerts,
    ) {}

    /**
     * New workspaces start a trial on the default plan (billing on only).
     */
    public function startTrial(Workspace $workspace): ?Subscription
    {
        if (! config('billing.enabled') || Subscription::query()->where('workspace_id', $workspace->getKey())->exists()) {
            return null;
        }

        $plan = Plan::query()->where('is_default', true)->where('is_active', true)->first();

        if (! $plan) {
            return null;
        }

        $subscription = new Subscription;
        $subscription->forceFill([
            'workspace_id' => $workspace->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => $plan->trial_days > 0 ? SubscriptionStatus::Trialing : SubscriptionStatus::Expired,
            'interval' => BillingInterval::Monthly,
            'trial_ends_at' => $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null,
        ])->save();

        return $subscription;
    }

    /**
     * An invoice for $plan: a renewal when it continues the current plan,
     * otherwise a new period starting when it's paid.
     */
    public function invoiceFor(Workspace $workspace, Plan $plan, BillingInterval $interval): Invoice
    {
        if (! $plan->is_active) {
            throw new InvalidArgumentException('This plan is no longer available.');
        }

        if ($plan->price($interval) <= 0) {
            throw new InvalidArgumentException('This plan has no '.strtolower($interval->getLabel()).' price.');
        }

        $subscription = Subscription::query()->where('workspace_id', $workspace->getKey())->first();
        $renewal = $this->continues($subscription, $plan, $interval);

        // Reuse an open invoice for the same thing instead of piling them up.
        $open = Invoice::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', InvoiceStatus::Open->value)
            ->where('plan_id', $plan->getKey())
            ->where('interval', $interval->value)
            ->where('amount', $plan->price($interval))
            ->latest('id')
            ->first();

        if ($open) {
            return $open;
        }

        $start = $renewal ? $subscription->current_period_end : null;

        return $this->createInvoice($workspace, $plan, $interval, $renewal, $start);
    }

    /**
     * Record a payment. Safe to call twice (webhook + return page).
     */
    public function markPaid(Invoice $invoice, ?string $gateway = null, ?string $transactionId = null): Invoice
    {
        $paid = DB::transaction(function () use ($invoice, $gateway, $transactionId): ?Invoice {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if ($invoice->status !== InvoiceStatus::Open) {
                return null;
            }

            $plan = Plan::query()->find($invoice->plan_id);
            $subscription = Subscription::query()->where('workspace_id', $invoice->workspace_id)->lockForUpdate()->first();
            $interval = $invoice->interval;

            $start = $plan && $this->continues($subscription, $plan, $interval) && $subscription->current_period_end
                ? $subscription->current_period_end
                : now();
            $end = $interval->addTo($start);

            $invoice->forceFill([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
                'gateway' => $gateway ?? $invoice->gateway,
                'period_start' => $start,
                'period_end' => $end,
                'meta' => [...($invoice->meta ?? []), 'transaction_id' => $transactionId],
            ])->save();

            if ($plan) {
                $subscription ??= (new Subscription)->forceFill(['workspace_id' => $invoice->workspace_id]);
                $subscription->forceFill([
                    'plan_id' => $plan->getKey(),
                    'status' => SubscriptionStatus::Active,
                    'interval' => $interval,
                    'trial_ends_at' => null,
                    'current_period_start' => $start,
                    'current_period_end' => $end,
                    'cancel_at_period_end' => false,
                    'canceled_at' => null,
                ])->save();

                // Other open invoices for this period are no longer needed.
                Invoice::query()
                    ->where('workspace_id', $invoice->workspace_id)
                    ->where('status', InvoiceStatus::Open->value)
                    ->whereKeyNot($invoice->getKey())
                    ->update(['status' => InvoiceStatus::Void->value]);
            }

            return $invoice;
        });

        if ($paid) {
            $this->alerts->send(
                $paid->workspace_id,
                "Payment received: {$paid->number}",
                "{$paid->formattedAmount()} for {$paid->description}. Thank you!",
                $this->billingUrl($paid->workspace_id),
                'Billing',
                'success',
            );
        }

        return $paid ?? $invoice->refresh();
    }

    public function cancel(Workspace $workspace): void
    {
        Subscription::query()
            ->where('workspace_id', $workspace->getKey())
            ->update(['cancel_at_period_end' => true, 'canceled_at' => now()]);
    }

    public function resume(Workspace $workspace): void
    {
        Subscription::query()
            ->where('workspace_id', $workspace->getKey())
            ->update(['cancel_at_period_end' => false, 'canceled_at' => null]);
    }

    /**
     * Set a workspace's plan by hand (super admin): no invoice.
     */
    public function assign(Workspace $workspace, Plan $plan, BillingInterval $interval, SubscriptionStatus $status, ?CarbonInterface $periodEnd): Subscription
    {
        $subscription = Subscription::query()->firstOrNew(['workspace_id' => $workspace->getKey()]);
        $subscription->forceFill([
            'workspace_id' => $workspace->getKey(),
            'plan_id' => $plan->getKey(),
            'interval' => $interval,
            'status' => $status,
            'trial_ends_at' => $status === SubscriptionStatus::Trialing ? $periodEnd : null,
            'current_period_start' => $status === SubscriptionStatus::Trialing ? null : ($subscription->current_period_start ?? now()),
            'current_period_end' => $status === SubscriptionStatus::Trialing ? null : $periodEnd,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ])->save();

        return $subscription;
    }

    /**
     * Hourly: end trials, create renewal invoices, mark missed payments
     * overdue and expire them after the grace period.
     *
     * @return array{trials_ended: int, renewals: int, past_due: int, expired: int}
     */
    public function run(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $counts = ['trials_ended' => 0, 'renewals' => 0, 'past_due' => 0, 'expired' => 0];

        Subscription::query()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->where('trial_ends_at', '<=', $now)
            ->each(function (Subscription $subscription) use (&$counts): void {
                $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
                $counts['trials_ended']++;
                $this->alerts->send($subscription->workspace_id, 'Your free trial has ended',
                    'Campaigns have stopped sending. Choose a plan to continue: your leads, campaigns and mailboxes are kept.',
                    $this->billingUrl($subscription->workspace_id), 'Choose a plan');
            });

        // Renewal invoices, a few days ahead.
        Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->where('cancel_at_period_end', false)
            ->where('current_period_end', '<=', $now->copy()->addDays((int) config('billing.renewal_notice_days', 3)))
            ->with('plan')
            ->each(function (Subscription $subscription) use (&$counts): void {
                $exists = Invoice::query()
                    ->where('workspace_id', $subscription->workspace_id)
                    ->where('is_renewal', true)
                    ->where('period_start', $subscription->current_period_end)
                    ->exists();

                if ($exists || ! $subscription->plan || $subscription->plan->price($subscription->interval) <= 0) {
                    return;
                }

                $invoice = $this->createInvoice($subscription->workspace()->firstOrFail(), $subscription->plan, $subscription->interval, true, $subscription->current_period_end);
                $counts['renewals']++;
                $this->alerts->send($subscription->workspace_id, "Invoice {$invoice->number}: {$invoice->formattedAmount()} due",
                    "Your {$subscription->plan->name} plan renews on {$subscription->current_period_end->toFormattedDateString()}. Pay the invoice to keep sending without interruption.",
                    $this->billingUrl($subscription->workspace_id), 'Pay now', 'warning');
            });

        Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->where('current_period_end', '<=', $now)
            ->each(function (Subscription $subscription) use (&$counts): void {
                if ($subscription->cancel_at_period_end) {
                    $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
                    $counts['expired']++;

                    return;
                }

                $subscription->forceFill(['status' => SubscriptionStatus::PastDue])->save();
                $counts['past_due']++;
                $this->alerts->send($subscription->workspace_id, 'Payment overdue',
                    'Your subscription period has ended. Pay the open invoice within '.config('billing.grace_days').' days to keep sending.',
                    $this->billingUrl($subscription->workspace_id), 'Pay now');
            });

        Subscription::query()
            ->where('status', SubscriptionStatus::PastDue->value)
            ->where('current_period_end', '<=', $now->copy()->subDays((int) config('billing.grace_days', 7)))
            ->each(function (Subscription $subscription) use (&$counts): void {
                $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
                $counts['expired']++;
                $this->alerts->send($subscription->workspace_id, 'Subscription expired',
                    'Campaigns have stopped sending. Pay the open invoice to continue.',
                    $this->billingUrl($subscription->workspace_id), 'Pay now');
            });

        return $counts;
    }

    protected function continues(?Subscription $subscription, Plan $plan, BillingInterval $interval): bool
    {
        return $subscription !== null
            && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
            && $subscription->plan_id === $plan->getKey()
            && $subscription->interval === $interval;
    }

    protected function createInvoice(Workspace $workspace, Plan $plan, BillingInterval $interval, bool $renewal, ?CarbonInterface $start): Invoice
    {
        return DB::transaction(function () use ($workspace, $plan, $interval, $renewal, $start): Invoice {
            $invoice = new Invoice;
            $invoice->forceFill([
                'workspace_id' => $workspace->getKey(),
                'plan_id' => $plan->getKey(),
                'number' => 'TMP-'.Str::uuid(),
                'status' => InvoiceStatus::Open,
                'interval' => $interval,
                'description' => "{$plan->name} plan, ".strtolower($interval->getLabel()).($renewal ? ' (renewal)' : ''),
                'currency' => $plan->currency,
                'amount' => $plan->price($interval),
                'is_renewal' => $renewal,
                'period_start' => $start,
                'period_end' => $start ? $interval->addTo($start) : null,
                'due_at' => $start ?? now(),
            ])->save();

            $invoice->forceFill([
                'number' => sprintf('%s-%s-%06d', config('billing.invoice_prefix', 'INV'), now()->format('Y'), $invoice->getKey()),
            ])->save();

            return $invoice;
        });
    }

    protected function billingUrl(int $workspaceId): ?string
    {
        try {
            $workspace = Workspace::query()->find($workspaceId);

            return $workspace ? Billing::getUrl(panel: 'app', tenant: $workspace) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
