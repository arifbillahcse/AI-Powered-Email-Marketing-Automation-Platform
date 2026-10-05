<?php

namespace App\Jobs;

use App\Enums\InboxMessageStatus;
use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\EmailAccount;
use App\Models\InboxMessage;
use App\Models\InboxThread;
use App\Models\Lead;
use App\Models\User;
use App\Services\Campaigns\CampaignMessageBuilder;
use App\Services\Leads\SuppressionList;
use App\Services\Mail\MailboxConnectionException;
use App\Services\Mail\MailboxTransportFactory;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends one hand-written email (a Unibox reply, or a one-off email that
 * starts a conversation) from the conversation's mailbox, in the same
 * thread. Not retried automatically (a retry could send it twice); the
 * user is told when it fails.
 */
class SendInboxReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public function __construct(
        public int $inboxMessageId,
    ) {
        $this->onQueue('sending');
    }

    public function handle(MailboxTransportFactory $transports, SuppressionList $suppressions): void
    {
        $message = InboxMessage::query()->find($this->inboxMessageId);

        if (! $message || $message->status !== InboxMessageStatus::Sending) {
            return;
        }

        $thread = InboxThread::query()->find($message->inbox_thread_id);
        $mailbox = $message->email_account_id ? EmailAccount::query()->find($message->email_account_id) : null;
        $lead = $thread ? Lead::query()->find($thread->lead_id) : null;

        if (! $thread || ! $lead) {
            $this->markFailed($message, 'The conversation no longer exists.');

            return;
        }

        if (! $mailbox) {
            $this->markFailed($message, 'The mailbox this conversation used has been removed.');

            return;
        }

        if ($suppressions->isSuppressed($lead->workspace_id, $lead->email)) {
            $this->markFailed($message, "{$lead->email} is on the suppression list (unsubscribed, bounced or blocked).");

            return;
        }

        $messageId = Str::uuid()->toString().'@'.$mailbox->domain();

        $email = (new Email)
            ->from(new Address($mailbox->email, $mailbox->from_name))
            ->to(new Address($lead->email, $lead->fullName()))
            ->subject((string) ($message->subject ?? ''))
            ->text($message->body.(filled($mailbox->signature) ? "\n\n".app(CampaignMessageBuilder::class)->htmlToText($mailbox->signature) : ''))
            ->html(nl2br(e($message->body), false).(filled($mailbox->signature) ? "\n<div class=\"signature\">{$mailbox->signature}</div>" : ''));

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $messageId);

        if ($message->in_reply_to) {
            $headers->addIdHeader('In-Reply-To', $message->in_reply_to);
            $headers->addIdHeader('References', array_slice(array_filter(explode(' ', (string) $message->references)), -10));
        }

        try {
            $transports->make($mailbox)->send($email);
        } catch (MailboxConnectionException|TransportExceptionInterface $exception) {
            $this->markFailed($message, mb_substr(strtok($exception->getMessage(), "\n") ?: 'Sending failed.', 0, 500));

            return;
        }

        $message->forceFill([
            'status' => InboxMessageStatus::Sent,
            'message_id' => $messageId,
            'from_email' => $mailbox->email,
            'from_name' => $mailbox->from_name,
            'sent_at' => now(),
        ])->save();

        $thread->forceFill([
            'snippet' => Str::limit('You: '.Str::squish($message->body), 250),
            'message_count' => $thread->message_count + 1,
            'last_message_at' => now(),
        ])->save();

        $lead->forceFill(['last_contacted_at' => now()]);

        if ($lead->status === LeadStatus::New) {
            $lead->status = LeadStatus::Contacted;
        }

        $lead->save();

        if ($message->in_reply_to) {
            $lead->logActivity(LeadActivityType::ReplySent, 'Reply sent: '.($message->subject ?? '(no subject)'));
        } else {
            $lead->logActivity(LeadActivityType::EmailSent, 'Sent "'.($message->subject ?? '(no subject)').'" (one-off email)');
        }
    }

    protected function markFailed(InboxMessage $message, string $error): void
    {
        $message->forceFill(['status' => InboxMessageStatus::Failed, 'error' => $error])->save();

        if ($message->user_id && ($user = User::query()->find($message->user_id))) {
            Notification::make()
                ->title($message->in_reply_to ? 'Your reply wasn\'t sent' : 'Your email to '.$message->to_email.' wasn\'t sent')
                ->body($error)
                ->danger()
                ->sendToDatabase($user);
        }
    }
}
