<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Filament\App\Pages\Billing;
use App\Models\Workspace;
use Throwable;

/**
 * The one-line notice at the top of every customer page: suspended,
 * trial ending, payment overdue or expired.
 */
class AccountBanner
{
    public function __construct(
        protected Entitlements $entitlements,
    ) {}

    /**
     * @return array{color: string, message: string, url: ?string, link: ?string}|null
     */
    public function for(?Workspace $workspace): ?array
    {
        if (! $workspace) {
            return null;
        }

        if ($workspace->isSuspended()) {
            return $this->banner('danger', (string) $this->entitlements->sendingBlockedReason($workspace));
        }

        // Client workspaces never see the agency's billing, only a block.
        if ($workspace->isClient()) {
            $reason = $this->entitlements->sendingBlockedReason($workspace);

            return $reason === null ? null : $this->banner('danger', $reason);
        }

        if (! $this->entitlements->billingEnabled()) {
            return null;
        }

        $subscription = $this->entitlements->subscription($workspace);

        return match (true) {
            $subscription === null || ! $subscription->isUsable() => $this->banner('danger', (string) $this->entitlements->sendingBlockedReason($workspace), $workspace, 'Choose a plan'),
            $subscription->status === SubscriptionStatus::PastDue => $this->banner('warning', 'Your payment is overdue. Pay the open invoice to keep sending.', $workspace, 'Pay now'),
            $subscription->status === SubscriptionStatus::Trialing && $subscription->trial_ends_at !== null => $this->banner(
                'info',
                'Free trial: '.max(1, (int) ceil(now()->diffInDays($subscription->trial_ends_at, true))).' days left.',
                $workspace,
                'Choose a plan',
            ),
            default => null,
        };
    }

    /**
     * @return array{color: string, message: string, url: ?string, link: ?string}
     */
    protected function banner(string $color, string $message, ?Workspace $workspace = null, ?string $link = null): array
    {
        $url = null;

        try {
            $url = $workspace && Billing::canAccess() ? Billing::getUrl(tenant: $workspace) : null;
        } catch (Throwable) {
            // No link outside a panel request.
        }

        return ['color' => $color, 'message' => $message, 'url' => $url, 'link' => $url ? $link : null];
    }
}
