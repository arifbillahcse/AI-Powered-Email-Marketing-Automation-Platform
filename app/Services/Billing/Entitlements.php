<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Carbon\CarbonInterface;
use Illuminate\Support\Number;

/**
 * What a workspace may do under its plan: limits (mailboxes, leads, team
 * members, emails per month), modules, and whether it may send at all.
 *
 * With billing off (the default for self-hosted installs) everything is
 * unlimited. A suspended workspace never sends, billing or not.
 */
class Entitlements
{
    public function billingEnabled(): bool
    {
        return (bool) config('billing.enabled');
    }

    /**
     * A client workspace is covered by its agency's subscription.
     */
    public function subscription(Workspace|int $workspace): ?Subscription
    {
        return Subscription::query()
            ->where('workspace_id', $this->billingWorkspaceId($workspace))
            ->with('plan')
            ->first();
    }

    /**
     * The workspace that pays: itself, or the agency of a client workspace.
     */
    public function billingWorkspaceId(Workspace|int $workspace): int
    {
        if ($workspace instanceof Workspace) {
            return $workspace->billingWorkspaceId();
        }

        return (int) (Workspace::query()->whereKey($workspace)->value('parent_id') ?? $workspace);
    }

    /**
     * The agency and all its client workspaces (or just the workspace).
     * Their mailboxes, leads and emails share the agency's plan limits.
     *
     * @return list<int>
     */
    public function familyIds(Workspace|int $workspace): array
    {
        $root = $this->billingWorkspaceId($workspace);

        return [$root, ...Workspace::query()->where('parent_id', $root)->pluck('id')->map(fn ($id): int => (int) $id)->all()];
    }

    /**
     * The plan whose limits apply now, or null when the workspace has no
     * usable subscription (inactive limits apply).
     */
    public function plan(Workspace|int $workspace): ?Plan
    {
        $subscription = $this->subscription($workspace);

        return $subscription?->isUsable() ? $subscription->plan : null;
    }

    /**
     * @return int|null Null means unlimited
     */
    public function limit(Workspace|int $workspace, string $key): ?int
    {
        if (! $this->billingEnabled()) {
            return null;
        }

        $plan = $this->plan($workspace);

        if ($plan) {
            return $plan->limit($key);
        }

        $inactive = config("billing.inactive_limits.{$key}");

        return $inactive === null ? null : (int) $inactive;
    }

    public function usage(Workspace|int $workspace, string $key): int
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->getKey() : $workspace;

        return match ($key) {
            'mailboxes' => EmailAccount::query()->whereIn('workspace_id', $this->familyIds($workspace))->count(),
            'leads' => Lead::query()->whereIn('workspace_id', $this->familyIds($workspace))->count(),
            'team_members' => Membership::query()->where('workspace_id', $workspaceId)->count()
                + WorkspaceInvitation::query()->where('workspace_id', $workspaceId)->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            'clients' => Workspace::query()->where('parent_id', $this->billingWorkspaceId($workspace))->count(),
            'emails_per_month' => EmailMessage::query()
                ->whereIn('workspace_id', $this->familyIds($workspace))
                ->where('sent_at', '>=', $this->monthStart($workspace))
                ->count(),
            default => 0,
        };
    }

    /**
     * How many more of $key the workspace may add (null = unlimited).
     */
    public function remaining(Workspace|int $workspace, string $key): ?int
    {
        $limit = $this->limit($workspace, $key);

        return $limit === null ? null : max(0, $limit - $this->usage($workspace, $key));
    }

    /**
     * A user-facing reason adding $count more of $key isn't allowed, or null.
     */
    public function denies(Workspace|int $workspace, string $key, int $count = 1): ?string
    {
        $remaining = $this->remaining($workspace, $key);

        if ($remaining === null || $remaining >= $count) {
            return null;
        }

        $limit = (int) $this->limit($workspace, $key);
        $label = strtolower(Plan::LIMITS[$key] ?? $key);

        return $this->plan($workspace)
            ? "Your plan includes {$this->format($limit)} {$label}. Upgrade on the Billing page to add more."
            : "Choose a plan on the Billing page to add more {$label}.";
    }

    /**
     * Why the workspace can't send campaign email right now, or null.
     */
    public function sendingBlockedReason(Workspace|int $workspace): ?string
    {
        $workspace = $workspace instanceof Workspace ? $workspace : Workspace::query()->find($workspace);

        if (! $workspace) {
            return 'The workspace no longer exists.';
        }

        if ($workspace->isSuspended()) {
            return 'This workspace is suspended'.($workspace->suspension_reason ? ": {$workspace->suspension_reason}" : '.').' Contact support.';
        }

        if ($workspace->isClient()) {
            $agency = Workspace::query()->find($workspace->parent_id);

            if ($agency?->isSuspended()) {
                return 'Sending is paused for this workspace. Contact your agency.';
            }

            if ($agency && $this->sendingBlockedReason($agency) !== null) {
                return 'Sending is paused for this workspace: the agency\'s plan isn\'t active or its monthly email limit is used up. Contact your agency.';
            }

            return null;
        }

        if (! $this->billingEnabled()) {
            return null;
        }

        $subscription = $this->subscription($workspace);

        if (! $subscription?->isUsable()) {
            return match (true) {
                $subscription === null => 'No plan yet. Choose one on the Billing page to start sending.',
                $subscription->status === SubscriptionStatus::Trialing => 'Your free trial has ended. Choose a plan on the Billing page to keep sending.',
                default => 'Your subscription has expired. Pay the open invoice on the Billing page to keep sending.',
            };
        }

        if ($this->remaining($workspace, 'emails_per_month') === 0) {
            return 'This month\'s email limit ('.$this->format((int) $this->limit($workspace, 'emails_per_month')).') is used up. Upgrade on the Billing page, or wait until next month.';
        }

        return null;
    }

    public function allowsModule(Workspace|int $workspace, string $module): bool
    {
        if (! modules()->enabled($module)) {
            return false;
        }

        if (! $this->billingEnabled()) {
            return true;
        }

        return (bool) $this->plan($workspace)?->includesModule($module);
    }

    public function monthStart(Workspace|int $workspace): CarbonInterface
    {
        $timezone = $workspace instanceof Workspace ? $workspace->timezone : (Workspace::query()->whereKey($workspace)->value('timezone') ?: 'UTC');

        return now($timezone)->startOfMonth()->utc();
    }

    protected function format(int $value): string
    {
        return (string) Number::format($value);
    }
}
