<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Services\Inbox\InboxSynchronizer;
use App\Services\Mail\MailboxConnectionException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks one mailbox for replies. Connection problems are recorded on the
 * mailbox and retried on the next poll, not by the queue.
 */
class SyncMailboxInbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public int $uniqueFor = 300;

    public function __construct(
        public int $emailAccountId,
    ) {
        $this->onQueue('imap');
    }

    public function uniqueId(): string
    {
        return (string) $this->emailAccountId;
    }

    public function handle(InboxSynchronizer $synchronizer): void
    {
        $mailbox = EmailAccount::query()->find($this->emailAccountId);

        if (! $mailbox) {
            return;
        }

        try {
            $synchronizer->sync($mailbox);
        } catch (MailboxConnectionException $exception) {
            $mailbox->forceFill([
                'imap_error' => mb_substr($exception->getMessage(), 0, 1000),
                'imap_synced_at' => now(),
            ])->save();
        }
    }
}
