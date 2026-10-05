<?php

namespace App\Services\Inbox;

use App\Enums\EmailAccountStatus;
use App\Enums\InboxMessageStatus;
use App\Jobs\SendInboxReply;
use App\Models\EmailAccount;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Lead;
use App\Models\User;
use App\Services\Campaigns\CampaignMessageBuilder;
use App\Services\Campaigns\TemplateRenderer;
use App\Services\Leads\SuppressionList;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Emails written by hand: replies in the Unibox, and one-off emails to a
 * single lead (which start a new Unibox conversation, so the lead's reply
 * lands there too). Both are queued (never sent during the request) and
 * replies are threaded under the conversation's last message.
 */
class InboxReplier
{
    public const MAX_LENGTH = 20_000;

    public function queue(InboxThread $thread, string $body, User $by): InboxMessage
    {
        $lead = Lead::query()->findOrFail($thread->lead_id);
        $last = $this->lastThreaded($thread);
        $subject = (string) ($last?->subject ?? $thread->subject ?? '');

        $message = new InboxMessage;
        $message->forceFill([
            'workspace_id' => $thread->workspace_id,
            'inbox_thread_id' => $thread->getKey(),
            'email_account_id' => $thread->email_account_id,
            'user_id' => $by->getKey(),
            'direction' => InboxMessage::OUTBOUND,
            'status' => InboxMessageStatus::Sending,
            'in_reply_to' => $last?->message_id,
            'references' => $last ? trim(($last->references ?? '').' '.$last->message_id) : null,
            'from_email' => '',
            'to_email' => $lead->email,
            'subject' => $subject === '' || Str::startsWith(Str::lower($subject), 're:') ? ($subject ?: null) : "Re: {$subject}",
            'body' => Str::limit(trim(str_replace(["\r\n", "\r"], "\n", $body)), self::MAX_LENGTH, ''),
        ])->save();

        $thread->forceFill(['unread' => false])->save();

        SendInboxReply::dispatch($message->getKey());

        return $message;
    }

    /**
     * Start a conversation with one lead: {{variables}} and spintax work as
     * in campaigns. Throws InvalidArgumentException with a user-facing
     * message when the email can't be sent.
     */
    public function compose(Lead $lead, EmailAccount $mailbox, string $subject, string $body, User $by): InboxMessage
    {
        if ($mailbox->workspace_id !== $lead->workspace_id) {
            throw new InvalidArgumentException('Choose a mailbox from this workspace.');
        }

        if ($mailbox->status !== EmailAccountStatus::Active) {
            throw new InvalidArgumentException("{$mailbox->email} is {$mailbox->status->getLabel()}. Fix or resume it first.");
        }

        if (app(SuppressionList::class)->isSuppressed($lead->workspace_id, $lead->email)) {
            throw new InvalidArgumentException("{$lead->email} is on the suppression list (unsubscribed, bounced or blocked).");
        }

        $renderer = app(TemplateRenderer::class);
        $variables = app(CampaignMessageBuilder::class)->variables($lead, $mailbox);
        $seed = 'direct-'.$lead->getKey().'-'.Str::random(8);
        $subject = Str::limit(Str::squish($renderer->render($subject, $variables, $seed)), 250, '');
        $body = Str::limit(trim(str_replace(["\r\n", "\r"], "\n", $renderer->render($body, $variables, $seed))), self::MAX_LENGTH, '');

        $thread = new InboxThread;
        $thread->forceFill([
            'workspace_id' => $lead->workspace_id,
            'lead_id' => $lead->getKey(),
            'email_account_id' => $mailbox->getKey(),
            'subject' => $subject ?: null,
            'snippet' => Str::limit('You: '.Str::squish($body), 250),
            'unread' => false,
            'last_message_at' => now(),
        ])->save();

        $message = new InboxMessage;
        $message->forceFill([
            'workspace_id' => $lead->workspace_id,
            'inbox_thread_id' => $thread->getKey(),
            'email_account_id' => $mailbox->getKey(),
            'user_id' => $by->getKey(),
            'direction' => InboxMessage::OUTBOUND,
            'status' => InboxMessageStatus::Sending,
            'from_email' => '',
            'to_email' => $lead->email,
            'subject' => $subject ?: null,
            'body' => $body,
        ])->save();

        SendInboxReply::dispatch($message->getKey());

        return $message;
    }

    /**
     * The conversation's latest delivered email (the lead's or ours), to
     * thread a reply under.
     */
    public function lastThreaded(InboxThread $thread): ?InboxMessage
    {
        return InboxMessage::query()
            ->where('inbox_thread_id', $thread->getKey())
            ->whereNotNull('message_id')
            ->latest('id') // arrival order: a lead's Date header can be off
            ->first();
    }
}
