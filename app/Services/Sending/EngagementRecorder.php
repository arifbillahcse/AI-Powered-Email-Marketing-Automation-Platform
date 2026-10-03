<?php

namespace App\Services\Sending;

use App\Enums\CampaignLeadStatus;
use App\Enums\EmailEventType;
use App\Enums\EmailMessageStatus;
use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Enums\SuppressionReason;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Services\Leads\SuppressionList;
use Illuminate\Support\Facades\DB;

/**
 * What happens to campaigns, leads and the suppression list when an email
 * is opened, clicked, bounces, or the lead unsubscribes.
 */
class EngagementRecorder
{
    public function __construct(
        protected SuppressionList $suppressions,
    ) {}

    public function open(EmailMessage $message): void
    {
        $first = $message->opened_at === null;

        EmailMessage::query()->whereKey($message->getKey())->update([
            'open_count' => DB::raw('open_count + 1'),
            'opened_at' => $message->opened_at ?? now(),
        ]);

        $message->recordEvent(EmailEventType::Open);

        if ($first) {
            $this->lead($message)?->logActivity(LeadActivityType::EmailOpened, "Opened \"{$message->subject}\"");
        }
    }

    public function click(EmailMessage $message, string $url): void
    {
        $first = $message->clicked_at === null;

        EmailMessage::query()->whereKey($message->getKey())->update([
            'click_count' => DB::raw('click_count + 1'),
            'clicked_at' => $message->clicked_at ?? now(),
        ]);

        $message->recordEvent(EmailEventType::Click, $url);

        if ($first) {
            $this->lead($message)?->logActivity(LeadActivityType::LinkClicked, "Clicked {$url}", ['url' => $url]);
        }
    }

    /**
     * Suppress the address, stop every campaign for the lead, mark the lead.
     * Safe to call more than once.
     */
    public function unsubscribe(EmailMessage $message): void
    {
        $lead = $this->lead($message);

        if (! $lead) {
            return;
        }

        $alreadyUnsubscribed = $lead->status === LeadStatus::Unsubscribed;

        $this->suppressions->add($lead->workspace_id, $lead->email, SuppressionReason::Unsubscribed);
        $this->stopLead($lead, CampaignLeadStatus::Unsubscribed);

        if (! $alreadyUnsubscribed) {
            $message->recordEvent(EmailEventType::Unsubscribe);
            $lead->update(['status' => LeadStatus::Unsubscribed]);
            $lead->logActivity(LeadActivityType::Unsubscribed, 'Unsubscribed via the link in an email');
        }
    }

    /**
     * The address doesn't exist: suppress it and stop the lead everywhere.
     */
    public function hardBounce(EmailMessage $message, string $reason): void
    {
        $lead = $this->lead($message);

        $message->forceFill([
            'status' => EmailMessageStatus::Bounced,
            'bounced_at' => now(),
            'error' => mb_substr($reason, 0, 1000),
        ])->save();

        $message->recordEvent(EmailEventType::Bounce);

        if (! $lead) {
            return;
        }

        $this->suppressions->add($lead->workspace_id, $lead->email, SuppressionReason::Bounced);
        $this->stopLead($lead, CampaignLeadStatus::Bounced);
        $lead->update(['status' => LeadStatus::Bounced]);
        $lead->logActivity(LeadActivityType::Bounced, 'Email bounced: '.mb_substr($reason, 0, 150));
    }

    /**
     * The lead replied (a real reply, not an out-of-office): record it on
     * the email, stop the sequence if the campaign says so, mark the lead.
     */
    public function reply(CampaignLead $campaignLead, ?EmailMessage $message, ?string $subject): void
    {
        $now = now();

        if ($message) {
            if ($message->replied_at === null) {
                $message->forceFill(['replied_at' => $now])->save();
            }

            $message->recordEvent(EmailEventType::Reply);
        }

        $stopOnReply = (bool) Campaign::query()->whereKey($campaignLead->campaign_id)->value('stop_on_reply');
        $updates = ['replied_at' => $campaignLead->replied_at ?? $now];

        if ($stopOnReply && in_array($campaignLead->status, [CampaignLeadStatus::Active, CampaignLeadStatus::Completed], true)) {
            $updates['status'] = CampaignLeadStatus::Replied;
            $updates['next_send_at'] = null;
        }

        $campaignLead->forceFill($updates)->save();

        $lead = Lead::query()->find($campaignLead->lead_id);

        if (! $lead) {
            return;
        }

        if (in_array($lead->status, [LeadStatus::New, LeadStatus::Contacted], true)) {
            $lead->update(['status' => LeadStatus::Replied]);
        }

        $lead->logActivity(LeadActivityType::Replied, 'Replied'.($subject ? " to \"{$subject}\"" : ''), [
            'campaign_id' => $campaignLead->campaign_id,
        ]);
    }

    protected function stopLead(Lead $lead, CampaignLeadStatus $status): void
    {
        CampaignLead::query()
            ->where('lead_id', $lead->getKey())
            ->where('status', CampaignLeadStatus::Active->value)
            ->update(['status' => $status->value, 'next_send_at' => null]);
    }

    protected function lead(EmailMessage $message): ?Lead
    {
        return Lead::query()->find($message->lead_id);
    }
}
