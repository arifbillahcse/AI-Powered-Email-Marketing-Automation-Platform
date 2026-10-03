<?php

namespace App\Services\Inbox;

use App\Enums\EmailMessageStatus;
use App\Enums\InboxMessageStatus;
use App\Enums\LeadActivityType;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Lead;
use App\Services\Sending\BounceClassifier;
use App\Services\Sending\EngagementRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Decides what an email that arrived in a connected mailbox means:
 *
 * - a bounce (delivery report): hard bounces suppress the lead;
 * - a reply to a campaign email: matched by In-Reply-To/References, or else
 *   by the sender being a lead this mailbox emailed. It goes to the Unibox,
 *   and real replies (not out-of-office) stop the sequence;
 * - anything else: ignored and never stored (it's the user's own mail).
 *
 * Safe to run twice on the same email.
 */
class InboundMailProcessor
{
    public function __construct(
        protected AutoReplyDetector $autoReplies,
        protected BounceClassifier $bounces,
        protected EngagementRecorder $engagement,
    ) {}

    public function process(EmailAccount $mailbox, string $raw, ?int $uid = null): ?InboxMessage
    {
        $email = ParsedEmail::fromRaw($raw);

        if ($email->fromEmail === null || $email->fromEmail === strtolower($mailbox->email)) {
            return null;
        }

        if ($email->isDeliveryReport()) {
            $this->bounce($mailbox, $email);

            return null;
        }

        [$campaignLead, $sent] = $this->match($mailbox, $email);

        if (! $campaignLead) {
            return null;
        }

        $messageId = $email->messageId ?? 'sha1-'.sha1($raw).'@missing-message-id';

        if (InboxMessage::query()->where('email_account_id', $mailbox->getKey())->where('message_id', $messageId)->exists()) {
            return null;
        }

        $autoReply = $this->autoReplies->isAutoReply($email);

        try {
            $message = DB::transaction(fn (): InboxMessage => $this->store($mailbox, $campaignLead, $sent, $email, $messageId, $autoReply, $uid));
        } catch (UniqueConstraintViolationException) {
            return null; // Imported by a parallel run.
        }

        if ($autoReply) {
            Lead::query()->find($campaignLead->lead_id)?->logActivity(LeadActivityType::AutoReplied, 'Auto-reply: '.($email->subject ?? '(no subject)'));
        } else {
            $this->engagement->reply($campaignLead, $sent, $email->subject);
        }

        return $message;
    }

    /**
     * @return array{0: ?CampaignLead, 1: ?EmailMessage}
     */
    protected function match(EmailAccount $mailbox, ParsedEmail $email): array
    {
        $workspaceId = $mailbox->workspace_id;
        $sent = null;

        if ($ids = $email->threadIds()) {
            $sent = EmailMessage::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('message_id', $ids)
                ->latest('id')
                ->first();

            // A reply to one of our Unibox replies continues that thread.
            if (! $sent) {
                $outbound = InboxMessage::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('direction', InboxMessage::OUTBOUND)
                    ->whereIn('message_id', $ids)
                    ->with('thread')
                    ->first();

                if ($outbound) {
                    return [CampaignLead::query()->find($outbound->thread->campaign_lead_id), null];
                }
            }
        }

        // No usable headers (some clients drop them): the sender is a lead
        // this mailbox has emailed.
        if (! $sent) {
            $leadId = Lead::query()->where('workspace_id', $workspaceId)->where('email', $email->fromEmail)->value('id');

            if ($leadId) {
                $sent = EmailMessage::query()
                    ->where('lead_id', $leadId)
                    ->where('email_account_id', $mailbox->getKey())
                    ->whereNotNull('sent_at')
                    ->latest('sent_at')
                    ->first();
            }
        }

        return [$sent ? CampaignLead::query()->find($sent->campaign_lead_id) : null, $sent];
    }

    protected function store(EmailAccount $mailbox, CampaignLead $campaignLead, ?EmailMessage $sent, ParsedEmail $email, string $messageId, bool $autoReply, ?int $uid): InboxMessage
    {
        $thread = InboxThread::query()->where('campaign_lead_id', $campaignLead->getKey())->lockForUpdate()->first();

        if (! $thread) {
            $thread = new InboxThread;
            $thread->forceFill([
                'workspace_id' => $mailbox->workspace_id,
                'campaign_lead_id' => $campaignLead->getKey(),
                'campaign_id' => $campaignLead->campaign_id,
                'lead_id' => $campaignLead->lead_id,
                'email_account_id' => $campaignLead->email_account_id ?? $mailbox->getKey(),
                'subject' => $sent?->subject ?? $email->subject,
            ])->save();
        }

        $receivedAt = $email->date ?? now();

        $message = new InboxMessage;
        $message->forceFill([
            'workspace_id' => $mailbox->workspace_id,
            'inbox_thread_id' => $thread->getKey(),
            'email_account_id' => $mailbox->getKey(),
            'email_message_id' => $sent?->getKey(),
            'direction' => InboxMessage::INBOUND,
            'status' => InboxMessageStatus::Received,
            'message_id' => $messageId,
            'in_reply_to' => $email->inReplyTo,
            'references' => implode(' ', array_slice($email->references, -20)) ?: null,
            'from_email' => mb_substr((string) $email->fromEmail, 0, 255),
            'from_name' => $email->fromName ? mb_substr($email->fromName, 0, 255) : null,
            'to_email' => mb_substr($email->toEmail ?? $mailbox->email, 0, 255),
            'subject' => $email->subject,
            'body' => $email->text,
            'auto_reply' => $autoReply,
            'imap_uid' => $uid,
            'sent_at' => $receivedAt,
        ])->save();

        $thread->forceFill([
            'snippet' => mb_substr($email->snippet(), 0, 255) ?: null,
            'unread' => true,
            'auto_reply' => $autoReply,
            'message_count' => $thread->message_count + 1,
            'last_message_at' => $receivedAt,
            'last_inbound_at' => $receivedAt,
        ])->save();

        return $message;
    }

    /**
     * Find the email that bounced (its Message-ID is quoted in the report)
     * and treat permanent failures like an SMTP-time hard bounce.
     */
    protected function bounce(EmailAccount $mailbox, ParsedEmail $email): void
    {
        $report = $this->bounces->classify($email->raw);

        if (! $report || ! $report['hard']) {
            return;
        }

        $sent = EmailMessage::query()
            ->where('workspace_id', $mailbox->workspace_id)
            ->whereIn('message_id', ParsedEmail::ids($email->raw, bareFallback: false))
            ->first();

        if (! $sent && $report['recipient']) {
            $leadId = Lead::query()->where('workspace_id', $mailbox->workspace_id)->where('email', $report['recipient'])->value('id');

            $sent = $leadId ? EmailMessage::query()
                ->where('lead_id', $leadId)
                ->where('email_account_id', $mailbox->getKey())
                ->whereNotNull('sent_at')
                ->latest('sent_at')
                ->first() : null;
        }

        if (! $sent || $sent->status === EmailMessageStatus::Bounced) {
            return;
        }

        $this->engagement->hardBounce($sent, trim(($report['status'] ?? '').' '.($report['diagnostic'] ?? '')));
    }
}
