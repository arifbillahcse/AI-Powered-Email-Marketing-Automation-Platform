<?php

namespace App\Services\Inbox;

use App\Enums\InboxMessageStatus;
use App\Jobs\SendInboxReply;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Replies written in the Unibox. They are queued (never sent during the
 * request) and go out from the mailbox the conversation started on,
 * threaded under the lead's last message.
 */
class InboxReplier
{
    public const MAX_LENGTH = 20_000;

    public function queue(InboxThread $thread, string $body, User $by): InboxMessage
    {
        $lead = Lead::query()->findOrFail($thread->lead_id);
        $last = $this->lastInbound($thread);
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

    public function lastInbound(InboxThread $thread): ?InboxMessage
    {
        return InboxMessage::query()
            ->where('inbox_thread_id', $thread->getKey())
            ->where('direction', InboxMessage::INBOUND)
            ->latest('sent_at')
            ->latest('id')
            ->first();
    }
}
