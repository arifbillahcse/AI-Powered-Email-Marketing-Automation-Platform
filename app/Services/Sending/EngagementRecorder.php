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
use App\Services\Compliance\DeliverabilityGuard;
use App\Services\Integrations\IntegrationEvents;
use App\Services\Leads\SuppressionList;
use Illuminate\Support\Facades\DB;

/**
 * What happens to campaigns, leads and the suppression list when an email
 * is opened, clicked, bounces, is reported as spam, or the lead
 * unsubscribes. Bounces and complaints also run the deliverability guard,
 * which pauses a mailbox or campaign whose rates get too high.
 */
class EngagementRecorder
{
    public function __construct(
        protected SuppressionList $suppressions,
        protected DeliverabilityGuard $guard,
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
            $lead = $this->lead($message);
            $lead?->logActivity(LeadActivityType::EmailOpened, "Opened \"{$message->subject}\"");
            $this->emit('email.opened', $message, $lead);
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
            $lead = $this->lead($message);
            $lead?->logActivity(LeadActivityType::LinkClicked, "Clicked {$url}", ['url' => $url]);
            $this->emit('email.clicked', $message, $lead, ['url' => $url]);
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

        // One unsubscribe per email, so analytics match the events exactly.
        if ($message->unsubscribed_at === null) {
            $message->forceFill(['unsubscribed_at' => now()])->save();
            $message->recordEvent(EmailEventType::Unsubscribe);
        }

        if (! $alreadyUnsubscribed) {
            $lead->update(['status' => LeadStatus::Unsubscribed]);
            $lead->logActivity(LeadActivityType::Unsubscribed, 'Unsubscribed via the link in an email');
            $this->emit('lead.unsubscribed', $message, $lead, ['reason' => 'unsubscribe_link']);
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

        if ($lead) {
            $this->suppressions->add($lead->workspace_id, $lead->email, SuppressionReason::Bounced);
            $this->stopLead($lead, CampaignLeadStatus::Bounced);
            $lead->update(['status' => LeadStatus::Bounced]);
            $lead->logActivity(LeadActivityType::Bounced, 'Email bounced: '.mb_substr($reason, 0, 150));
        }

        $this->emit('email.bounced', $message, $lead, ['reason' => mb_substr($reason, 0, 300)]);

        $this->guard->afterProblem($message);
    }

    /**
     * The lead marked the email as spam (a feedback-loop report): never
     * email them again, and check the sender's complaint rate. Safe to call
     * more than once.
     */
    public function complaint(EmailMessage $message): void
    {
        if ($message->complained_at !== null) {
            return;
        }

        $message->forceFill(['complained_at' => now()])->save();
        $message->recordEvent(EmailEventType::Complaint);

        if ($lead = $this->lead($message)) {
            $this->suppressions->add($lead->workspace_id, $lead->email, SuppressionReason::Complaint);
            $this->stopLead($lead, CampaignLeadStatus::Unsubscribed);
            $lead->update(['status' => LeadStatus::Unsubscribed]);
            $lead->logActivity(LeadActivityType::Complained, "Marked \"{$message->subject}\" as spam");
            $this->emit('lead.unsubscribed', $message, $lead, ['reason' => 'spam_complaint']);
        }

        $this->guard->afterProblem($message);
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

        if ($lead) {
            $this->leadReplied($lead, $subject, ['campaign_id' => $campaignLead->campaign_id]);
        }
    }

    /**
     * A lead answered (a campaign email, or a one-off email with no campaign).
     *
     * @param  array<string, mixed>  $properties
     */
    public function leadReplied(Lead $lead, ?string $subject, array $properties = []): void
    {
        if (in_array($lead->status, [LeadStatus::New, LeadStatus::Contacted], true)) {
            $lead->update(['status' => LeadStatus::Replied]);
        }

        $lead->logActivity(LeadActivityType::Replied, 'Replied'.($subject ? " to \"{$subject}\"" : ''), $properties);

        app(IntegrationEvents::class)->fire($lead->workspace_id, 'lead.replied', $lead, [
            'reply' => ['subject' => $subject],
        ], $properties['campaign_id'] ?? null);
    }

    /**
     * Tell integrations (webhooks, Slack, CRMs) about an email event.
     *
     * @param  array<string, mixed>  $data
     */
    protected function emit(string $event, EmailMessage $message, ?Lead $lead, array $data = []): void
    {
        app(IntegrationEvents::class)->fire($message->workspace_id, $event, $lead, [
            'email' => ['id' => $message->getKey(), 'subject' => $message->subject, 'step' => $message->step_position, 'variant' => $message->variant],
            ...$data,
        ], $message->campaign_id);
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
