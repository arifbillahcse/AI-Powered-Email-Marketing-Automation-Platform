<?php

namespace App\Services\Sending;

use App\Enums\CampaignLeadStatus;
use App\Enums\CampaignStatus;
use App\Enums\EmailAccountStatus;
use App\Enums\EmailMessageStatus;
use App\Jobs\SendCampaignEmail;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\Campaigns\CampaignLauncher;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs every minute. For each mailbox that may send now, picks the next due
 * lead from the campaigns it serves and queues one SendCampaignEmail job.
 *
 * Limits honoured: campaign and mailbox send windows (days + hours in their
 * time zones), campaign and mailbox daily limits, the mailbox's random gap
 * between emails, sticky mailbox per lead, and the suppression list.
 */
class SendScheduler
{
    public function __construct(
        protected CampaignLauncher $launcher,
    ) {}

    /**
     * @return int Emails queued
     */
    public function tick(?CarbonInterface $now = null): int
    {
        $now ??= now();

        $campaigns = Campaign::query()
            ->where('status', CampaignStatus::Active->value)
            ->where('is_template', false)
            ->get();

        $this->stopSuppressedLeads($campaigns);
        $campaigns = $this->completeFinished($campaigns);

        // Campaigns inside their window, with how many more they may send today.
        $remaining = [];

        foreach ($campaigns as $campaign) {
            if (! SendWindow::isOpen($now, $campaign->timezone, $campaign->send_days, $campaign->send_window_start, $campaign->send_window_end)) {
                continue;
            }

            $sentToday = EmailMessage::query()
                ->where('campaign_id', $campaign->getKey())
                ->where('sent_at', '>=', SendWindow::startOfDay($now, $campaign->timezone))
                ->count();

            if ($sentToday < $campaign->daily_limit) {
                $remaining[$campaign->getKey()] = $campaign->daily_limit - $sentToday;
            }
        }

        if ($remaining === []) {
            return 0;
        }

        $pools = DB::table('campaign_email_account')
            ->whereIn('campaign_id', array_keys($remaining))
            ->get()
            ->groupBy('email_account_id')
            ->map(fn (Collection $rows): array => $rows->pluck('campaign_id')->map(fn ($id): int => (int) $id)->all());

        $mailboxes = EmailAccount::query()
            ->whereIn('id', $pools->keys())
            ->where('status', EmailAccountStatus::Active->value)
            ->where(fn (Builder $query) => $query->whereNull('next_send_at')->orWhere('next_send_at', '<=', $now))
            ->with('workspace')
            ->get();

        $activeMailboxIds = EmailAccount::query()->where('status', EmailAccountStatus::Active->value)->pluck('id')->all();
        $queued = 0;

        foreach ($mailboxes as $mailbox) {
            $campaignIds = array_values(array_filter(
                $pools[$mailbox->getKey()] ?? [],
                fn (int $id): bool => ($remaining[$id] ?? 0) > 0,
            ));

            if ($campaignIds === [] || ! $this->mailboxCanSend($mailbox, $now)) {
                continue;
            }

            $campaignLead = $this->claimNextLead($campaignIds, $mailbox, $activeMailboxIds, $now);

            if (! $campaignLead) {
                continue;
            }

            $mailbox->forceFill([
                'next_send_at' => $now->copy()->addSeconds(random_int($mailbox->min_delay_seconds, max($mailbox->min_delay_seconds, $mailbox->max_delay_seconds))),
            ])->save();

            SendCampaignEmail::dispatch($campaignLead->getKey(), $mailbox->getKey(), $campaignLead->steps_sent + 1);

            $remaining[$campaignLead->campaign_id]--;
            $queued++;
        }

        return $queued;
    }

    protected function mailboxCanSend(EmailAccount $mailbox, CarbonInterface $now): bool
    {
        $timezone = $mailbox->workspace->timezone;

        if (! SendWindow::isOpen($now, $timezone, $mailbox->send_days, $mailbox->send_window_start, $mailbox->send_window_end)) {
            return false;
        }

        $sentToday = EmailMessage::query()
            ->where('email_account_id', $mailbox->getKey())
            ->whereIn('status', [EmailMessageStatus::Sent->value, EmailMessageStatus::Bounced->value])
            ->where('sent_at', '>=', SendWindow::startOfDay($now, $timezone))
            ->count();

        return $sentToday < $mailbox->daily_limit;
    }

    /**
     * Pick and atomically claim the next due lead for this mailbox. Leads keep
     * the mailbox that sent them their first email, unless it's no longer active.
     *
     * @param  list<int>  $campaignIds
     * @param  list<int>  $activeMailboxIds
     */
    protected function claimNextLead(array $campaignIds, EmailAccount $mailbox, array $activeMailboxIds, CarbonInterface $now): ?CampaignLead
    {
        $candidates = CampaignLead::query()
            ->whereIn('campaign_id', $campaignIds)
            ->where('status', CampaignLeadStatus::Active->value)
            ->where('next_send_at', '<=', $now)
            ->where(fn (Builder $query) => $query
                ->whereNull('email_account_id')
                ->orWhere('email_account_id', $mailbox->getKey())
                ->orWhereNotIn('email_account_id', $activeMailboxIds))
            ->whereHas('lead', fn (Builder $lead) => $lead->whereNotSuppressed())
            ->orderBy('next_send_at')
            ->orderBy('id')
            ->limit(5)
            ->get();

        foreach ($candidates as $candidate) {
            // Claim: only one scheduler run (or overlapping run) can win.
            $claimed = CampaignLead::query()
                ->whereKey($candidate->getKey())
                ->where('status', CampaignLeadStatus::Active->value)
                ->where('next_send_at', $candidate->next_send_at)
                ->update([
                    'email_account_id' => $mailbox->getKey(),
                    'next_send_at' => $now->copy()->addMinutes((int) config('outreach.sending.lease_minutes')),
                    'updated_at' => $now,
                ]);

            if ($claimed === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Leads that got suppressed after enrollment are stopped, so they
     * don't sit in the queue forever.
     *
     * @param  Collection<int, Campaign>  $campaigns
     */
    protected function stopSuppressedLeads(Collection $campaigns): void
    {
        if ($campaigns->isEmpty()) {
            return;
        }

        CampaignLead::query()
            ->whereIn('campaign_id', $campaigns->modelKeys())
            ->where('status', CampaignLeadStatus::Active->value)
            ->whereHas('lead', fn (Builder $lead) => $lead->whereSuppressed())
            ->update(['status' => CampaignLeadStatus::Stopped->value, 'next_send_at' => null]);
    }

    /**
     * Campaigns with nobody left in their sequence are marked completed.
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return Collection<int, Campaign> Campaigns still running
     */
    protected function completeFinished(Collection $campaigns): Collection
    {
        return $campaigns->reject(function (Campaign $campaign): bool {
            $hasActiveLeads = CampaignLead::query()
                ->where('campaign_id', $campaign->getKey())
                ->where('status', CampaignLeadStatus::Active->value)
                ->exists();

            if (! $hasActiveLeads) {
                $this->launcher->complete($campaign);
            }

            return ! $hasActiveLeads;
        })->values();
    }
}
