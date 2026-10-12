<?php

namespace App\Jobs;

use App\Models\WarmupMessage;
use App\Services\Mail\MailboxConnectionException;
use App\Services\Mail\MailboxTransportFactory;
use App\Services\Warmup\WarmupContent;
use App\Services\Warmup\WarmupPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends one warmup email: a new conversation from $senderId to a pool
 * partner, or (with $parentId) the recipient's reply to a warmup email.
 * Not retried: a failure is recorded and the next run tries again.
 */
class SendWarmupEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public function __construct(
        public int $senderId,
        public ?int $parentId = null,
    ) {
        $this->onQueue('sending');
    }

    public function handle(MailboxTransportFactory $transports, WarmupPlanner $planner, WarmupContent $content): void
    {
        $sender = $planner->pool()->find($this->senderId);

        if (! $sender || ! $planner->allowed($sender)) {
            return;
        }

        $parent = $this->parentId ? WarmupMessage::query()->find($this->parentId) : null;

        if ($this->parentId) {
            // Reply to whoever sent the parent, if they still warm up.
            $recipient = $parent && $parent->recipient_id === $sender->getKey() ? $planner->pool()->find($parent->sender_id) : null;
        } else {
            $recipient = $planner->partner($sender);

            if (! $recipient) {
                $sender->forceFill(['warmup_error' => 'No warmup partner is available yet. Warmup needs other mailboxes on a different domain.'])->save();

                return;
            }
        }

        if (! $recipient || ! $planner->allowed($recipient)) {
            return;
        }

        if ($parent && WarmupMessage::query()->where('parent_id', $parent->getKey())->exists()) {
            return;
        }

        if ($parent) {
            $subject = $content->replySubject($parent->subject);
            $body = $content->reply($sender->from_name, $recipient->from_name);
        } else {
            ['subject' => $subject, 'body' => $body] = $content->compose($sender->from_name, $recipient->from_name);
        }

        try {
            // The unique parent_id makes sure a warmup email gets one reply.
            // Own transaction (a savepoint when nested), so a duplicate
            // doesn't abort an outer PostgreSQL transaction.
            $message = DB::transaction(fn (): WarmupMessage => WarmupMessage::query()->create([
                'token' => WarmupMessage::newToken(),
                'sender_id' => $sender->getKey(),
                'recipient_id' => $recipient->getKey(),
                'parent_id' => $parent?->getKey(),
                'depth' => $parent ? $parent->depth + 1 : 0,
                'subject' => Str::limit($subject, 250, ''),
            ]));
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $messageId = Str::uuid()->toString().'@'.$sender->domain();

        $email = (new Email)
            ->from(new Address($sender->email, $sender->from_name))
            ->to(new Address($recipient->email, $recipient->from_name))
            ->subject($subject)
            ->text($body);

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $messageId);
        $headers->addTextHeader((string) config('outreach.warmup.header'), $message->token);

        if ($parent?->message_id) {
            $headers->addIdHeader('In-Reply-To', $parent->message_id);
            $headers->addIdHeader('References', $parent->message_id);
        }

        try {
            $transports->make($sender)->send($email);
        } catch (MailboxConnectionException|TransportExceptionInterface $exception) {
            $error = mb_substr(strtok($exception->getMessage(), "\n") ?: 'Sending failed.', 0, 500);

            $message->forceFill(['status' => 'failed', 'error' => $error])->save();
            $sender->forceFill(['warmup_error' => $error])->save();

            return;
        }

        $message->forceFill(['message_id' => $messageId, 'sent_at' => now()])->save();
        $parent?->forceFill(['replied_at' => now()])->save();

        if ($sender->warmup_error !== null) {
            $sender->forceFill(['warmup_error' => null])->save();
        }
    }
}
