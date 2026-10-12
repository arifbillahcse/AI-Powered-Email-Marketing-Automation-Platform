<?php

namespace App\Services\Compliance;

use App\Enums\CampaignStatus;
use App\Enums\EmailAccountStatus;
use App\Filament\App\Resources\Campaigns\CampaignResource;
use App\Filament\App\Resources\EmailAccounts\EmailAccountResource;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Pauses a mailbox or campaign automatically when its bounce or spam-
 * complaint rate goes over the safety limits (config outreach.safety), and
 * tells the workspace's owners and admins why. Runs after every bounce and
 * complaint: the rates only go up when one of those happens.
 */
class DeliverabilityGuard
{
    public function __construct(
        protected WorkspaceAlerts $alerts,
    ) {}

    /**
     * Check the mailbox and campaign an email was sent from.
     */
    public function afterProblem(EmailMessage $message): void
    {
        if (! config('outreach.safety.enabled')) {
            return;
        }

        if ($message->email_account_id && ($mailbox = EmailAccount::query()->find($message->email_account_id))) {
            $this->checkMailbox($mailbox);
        }

        if ($message->campaign_id && ($campaign = Campaign::query()->find($message->campaign_id))) {
            $this->checkCampaign($campaign);
        }
    }

    /**
     * @return string|null Why the mailbox was paused, or null if it's fine
     */
    public function checkMailbox(EmailAccount $mailbox): ?string
    {
        if ($mailbox->status !== EmailAccountStatus::Active) {
            return null;
        }

        $reason = $this->problem(EmailMessage::query()->where('email_account_id', $mailbox->getKey()), $mailbox->safety_reset_at);

        if ($reason === null) {
            return null;
        }

        $reason = "Auto-paused: {$reason}";
        $mailbox->forceFill(['status' => EmailAccountStatus::Paused, 'paused_reason' => $reason])->save();

        $this->alerts->send(
            $mailbox->workspace_id,
            "Sending paused for {$mailbox->email}",
            "{$reason} Clean the lead list (verify the addresses), then resume the mailbox.",
            $this->url(fn (Workspace $workspace): string => EmailAccountResource::getUrl('edit', ['record' => $mailbox], panel: 'app', tenant: $workspace), $mailbox->workspace_id),
            'Open mailbox',
        );

        return $reason;
    }

    /**
     * @return string|null Why the campaign was paused, or null if it's fine
     */
    public function checkCampaign(Campaign $campaign): ?string
    {
        if ($campaign->status !== CampaignStatus::Active) {
            return null;
        }

        $reason = $this->problem(EmailMessage::query()->where('campaign_id', $campaign->getKey()), $campaign->safety_reset_at);

        if ($reason === null) {
            return null;
        }

        $reason = "Auto-paused: {$reason}";
        $campaign->forceFill(['status' => CampaignStatus::Paused, 'paused_at' => now(), 'paused_reason' => $reason])->save();

        $this->alerts->send(
            $campaign->workspace_id,
            "Campaign \"{$campaign->name}\" paused",
            "{$reason} Remove bad leads or fix the copy, then resume it.",
            $this->url(fn (Workspace $workspace): string => CampaignResource::getUrl('edit', ['record' => $campaign], panel: 'app', tenant: $workspace), $campaign->workspace_id),
            'Open campaign',
        );

        return $reason;
    }

    /**
     * Bounce and complaint rates for emails sent in the safety window.
     *
     * @param  Builder<EmailMessage>  $emails
     * @return array{sent: int, bounced: int, complained: int, bounce_rate: float, complaint_rate: float}
     */
    public function rates(Builder $emails, ?CarbonInterface $since = null): array
    {
        $from = now()->subDays((int) config('outreach.safety.window_days', 7));

        if ($since && $since->greaterThan($from)) {
            $from = $since;
        }

        $row = (clone $emails)
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $from)
            ->toBase()
            ->selectRaw('count(*) as sent, count(bounced_at) as bounced, count(complained_at) as complained')
            ->first();

        $sent = (int) ($row->sent ?? 0);
        $bounced = (int) ($row->bounced ?? 0);
        $complained = (int) ($row->complained ?? 0);

        return [
            'sent' => $sent,
            'bounced' => $bounced,
            'complained' => $complained,
            'bounce_rate' => $sent ? round($bounced / $sent * 100, 1) : 0.0,
            'complaint_rate' => $sent ? round($complained / $sent * 100, 2) : 0.0,
        ];
    }

    /**
     * @param  Builder<EmailMessage>  $emails
     */
    protected function problem(Builder $emails, ?CarbonInterface $since): ?string
    {
        $rates = $this->rates($emails, $since);

        if ($rates['sent'] < (int) config('outreach.safety.min_sent', 20)) {
            return null;
        }

        $days = (int) config('outreach.safety.window_days', 7);
        $maxComplaints = (float) config('outreach.safety.max_complaint_rate', 0.3);
        $maxBounces = (float) config('outreach.safety.max_bounce_rate', 5.0);

        if ($rates['complained'] > 0 && $rates['complaint_rate'] > $maxComplaints) {
            return sprintf('spam complaint rate %s%% (%d of %d emails in the last %d days) is over the %s%% limit.',
                $this->format($rates['complaint_rate']), $rates['complained'], $rates['sent'], $days, $this->format($maxComplaints));
        }

        if ($rates['bounced'] > 0 && $rates['bounce_rate'] > $maxBounces) {
            return sprintf('bounce rate %s%% (%d of %d emails in the last %d days) is over the %s%% limit.',
                $this->format($rates['bounce_rate']), $rates['bounced'], $rates['sent'], $days, $this->format($maxBounces));
        }

        return null;
    }

    protected function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  callable(Workspace): string  $build
     */
    protected function url(callable $build, int $workspaceId): ?string
    {
        try {
            $workspace = Workspace::query()->find($workspaceId);

            return $workspace ? $build($workspace) : null;
        } catch (Throwable) {
            return null; // A link is a convenience; never let it stop the pause.
        }
    }
}
