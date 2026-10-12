<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Services\Mail\MailboxConnectionException;
use App\Services\Warmup\WarmupInboxProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Opens, rescues, files and replies to the warmup emails one mailbox
 * received. Connection problems are shown on the Warmup page and retried
 * on the next run, not by the queue.
 */
class ProcessWarmupInbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public int $uniqueFor = 600;

    public function __construct(
        public int $emailAccountId,
    ) {
        $this->onQueue('imap');
    }

    public function uniqueId(): string
    {
        return 'warmup-'.$this->emailAccountId;
    }

    public function handle(WarmupInboxProcessor $processor): void
    {
        $mailbox = EmailAccount::query()->find($this->emailAccountId);

        if (! $mailbox) {
            return;
        }

        try {
            $processor->process($mailbox);
        } catch (MailboxConnectionException $exception) {
            $mailbox->forceFill([
                'warmup_error' => 'Inbox check: '.mb_substr($exception->getMessage(), 0, 900),
                'warmup_checked_at' => now(),
            ])->save();
        }
    }
}
