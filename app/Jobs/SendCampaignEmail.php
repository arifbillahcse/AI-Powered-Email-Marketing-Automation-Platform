<?php

namespace App\Jobs;

use App\Enums\CampaignLeadStatus;
use App\Enums\CampaignStatus;
use App\Enums\EmailEventType;
use App\Enums\EmailMessageStatus;
use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\CampaignLead;
use App\Models\CampaignStep;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\Campaigns\CampaignMessageBuilder;
use App\Services\Leads\SuppressionList;
use App\Services\Mail\MailboxConnectionException;
use App\Services\Mail\MailboxTransportFactory;
use App\Services\Sending\EngagementRecorder;
use App\Services\Sending\LinkTracker;
use App\Services\Sending\SmtpFailure;
use App\Services\Sending\TrackingUrls;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends the next step of a campaign to one lead from one mailbox. Queued by
 * the SendScheduler. Every condition is re-checked here because time passes
 * between scheduling and sending.
 */
class SendCampaignEmail implements ShouldQueue
{
    use Queueable;

    /** Retries are handled by rescheduling the lead, not by the queue. */
    public int $tries = 1;

    public int $timeout = 45;

    /**
     * @param  int  $stepPosition  The step this job was queued for. If the lead
     *                             has moved on (a duplicate job), nothing is sent.
     */
    public function __construct(
        public int $campaignLeadId,
        public int $emailAccountId,
        public int $stepPosition,
    ) {
        $this->onQueue('sending');
    }

    public function handle(
        MailboxTransportFactory $transports,
        CampaignMessageBuilder $builder,
        SuppressionList $suppressions,
        TrackingUrls $urls,
        LinkTracker $links,
        EngagementRecorder $engagement,
    ): void {
        $campaignLead = CampaignLead::query()->with(['campaign.workspace', 'lead'])->find($this->campaignLeadId);
        $mailbox = EmailAccount::query()->find($this->emailAccountId);

        if (! $campaignLead || ! $mailbox || $campaignLead->status !== CampaignLeadStatus::Active) {
            return;
        }

        if ($campaignLead->steps_sent + 1 !== $this->stepPosition) {
            return;
        }

        $campaign = $campaignLead->campaign;
        $lead = $campaignLead->lead;

        if ($campaign->status !== CampaignStatus::Active || ! $mailbox->isActive()) {
            $this->release($campaignLead);

            return;
        }

        // Last-moment compliance check.
        if ($suppressions->isSuppressed($lead->workspace_id, $lead->email)) {
            $campaignLead->forceFill(['status' => CampaignLeadStatus::Stopped, 'next_send_at' => null])->save();

            return;
        }

        $step = CampaignStep::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('position', $this->stepPosition)
            ->first();

        if (! $step) {
            $campaignLead->forceFill(['status' => CampaignLeadStatus::Completed, 'next_send_at' => null])->save();

            return;
        }

        // Reserve this step for this lead. If another run already did, stop.
        $message = new EmailMessage;
        $message->forceFill([
            'workspace_id' => $campaign->workspace_id,
            'campaign_id' => $campaign->getKey(),
            'campaign_lead_id' => $campaignLead->getKey(),
            'campaign_step_id' => $step->getKey(),
            'lead_id' => $lead->getKey(),
            'email_account_id' => $mailbox->getKey(),
            'step_position' => $step->position,
            'token' => Str::random(40),
            'message_id' => Str::uuid()->toString().'@'.$mailbox->domain(),
        ]);

        try {
            $message->save();
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $unsubscribeUrl = $urls->unsubscribe($mailbox, $message->token);
        $content = $builder->build($campaign, $step, $lead, $mailbox, $unsubscribeUrl);

        // AI content used without a fallback but not approved for this lead
        // yet: never send a half-empty email. Check again later.
        if (array_intersect($content['missing'], CampaignMessageBuilder::aiVariables()) !== []) {
            $message->delete();
            $campaignLead->forceFill(['next_send_at' => now()->addMinutes((int) config('outreach.ai.awaiting_review_minutes', 60))])->save();

            return;
        }

        $message->forceFill(['subject' => mb_substr($content['subject'], 0, 255)])->save();

        $email = (new Email)
            ->from(new Address($mailbox->email, $mailbox->from_name))
            ->to(new Address($lead->email, $lead->fullName()))
            ->subject($content['subject'])
            ->text($content['text']);

        if ($content['html'] !== null) {
            $html = $content['html'];

            if ($campaign->track_clicks) {
                $html = $links->rewriteLinks($html, fn (string $url): string => $urls->click($mailbox, $message->token, $url), [$unsubscribeUrl]);
            }

            if ($campaign->track_opens) {
                $html = $links->appendPixel($html, $urls->open($mailbox, $message->token));
            }

            $email->html($html);
        }

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $message->message_id);
        // RFC 8058 one-click unsubscribe (required by Gmail and Yahoo for bulk senders).
        $headers->addTextHeader('List-Unsubscribe', "<{$unsubscribeUrl}>");
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        if ($content['reply_in_thread']) {
            $previous = $campaignLead->messages()->whereNotNull('sent_at')->pluck('message_id')->filter()->values()->all();

            if ($previous !== []) {
                $headers->addIdHeader('In-Reply-To', end($previous));
                $headers->addIdHeader('References', $previous);
            }
        }

        try {
            $transports->make($mailbox)->send($email);
        } catch (MailboxConnectionException|TransportExceptionInterface $exception) {
            $this->handleFailure($exception, $message, $campaignLead, $mailbox, $engagement);

            return;
        }

        $this->recordSent($message, $campaignLead, $step);
    }

    protected function recordSent(EmailMessage $message, CampaignLead $campaignLead, CampaignStep $step): void
    {
        $message->forceFill(['status' => EmailMessageStatus::Sent, 'sent_at' => now()])->save();
        $message->recordEvent(EmailEventType::Sent);

        $nextStep = CampaignStep::query()
            ->where('campaign_id', $campaignLead->campaign_id)
            ->where('position', $step->position + 1)
            ->first();

        $campaignLead->forceFill([
            'steps_sent' => $step->position,
            'last_sent_at' => now(),
            'status' => $nextStep ? CampaignLeadStatus::Active : CampaignLeadStatus::Completed,
            'next_send_at' => $nextStep ? now()->addDays($nextStep->delay_days) : null,
        ])->save();

        $lead = $campaignLead->lead;
        $lead->forceFill(['last_contacted_at' => now()]);

        if ($lead->status === LeadStatus::New) {
            $lead->status = LeadStatus::Contacted;
        }

        $lead->save();
        $lead->logActivity(LeadActivityType::EmailSent, "Sent \"{$message->subject}\" (email {$step->position}, {$campaignLead->campaign->name})", [
            'email_message_id' => $message->getKey(),
        ]);
    }

    protected function handleFailure(Throwable $exception, EmailMessage $message, CampaignLead $campaignLead, EmailAccount $mailbox, EngagementRecorder $engagement): void
    {
        $error = mb_substr(strtok($exception->getMessage(), "\n") ?: 'Unknown SMTP error', 0, 1000);
        $failure = $exception instanceof MailboxConnectionException ? SmtpFailure::MailboxAuth : SmtpFailure::classify($exception);

        if ($failure === SmtpFailure::HardBounce) {
            $message->forceFill(['sent_at' => now()])->save();
            $engagement->hardBounce($message, $error);

            return;
        }

        // Free the step so it can be retried, and try again later.
        $message->delete();

        if ($failure === SmtpFailure::MailboxAuth) {
            $mailbox->markTested("SMTP: {$error}");
        }

        $this->release($campaignLead);
    }

    protected function release(CampaignLead $campaignLead): void
    {
        $campaignLead->forceFill([
            'next_send_at' => now()->addMinutes((int) config('outreach.sending.retry_minutes')),
        ])->save();
    }
}
