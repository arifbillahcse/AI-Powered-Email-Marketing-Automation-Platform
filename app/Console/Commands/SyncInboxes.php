<?php

namespace App\Console\Commands;

use App\Enums\EmailAccountStatus;
use App\Jobs\SyncMailboxInbox;
use App\Models\EmailAccount;
use Illuminate\Console\Command;

class SyncInboxes extends Command
{
    protected $signature = 'inbox:sync';

    protected $description = 'Check every connected mailbox for replies (queues one job per mailbox)';

    public function handle(): int
    {
        $count = 0;

        EmailAccount::query()
            ->whereIn('status', [EmailAccountStatus::Active->value, EmailAccountStatus::Paused->value])
            ->select('id')
            ->chunkById(500, function ($mailboxes) use (&$count): void {
                foreach ($mailboxes as $mailbox) {
                    SyncMailboxInbox::dispatch($mailbox->id);
                    $count++;
                }
            });

        $this->info("Queued {$count} mailbox checks.");

        return self::SUCCESS;
    }
}
