<?php

namespace App\Console\Commands;

use App\Enums\EmailAccountStatus;
use App\Jobs\ProcessWarmupInbox;
use App\Models\EmailAccount;
use App\Models\WarmupMessage;
use Illuminate\Console\Command;

class SyncWarmupInboxes extends Command
{
    protected $signature = 'warmup:sync';

    protected $description = 'Check the mailboxes that have warmup emails waiting (open, rescue from spam, reply)';

    public function handle(): int
    {
        if (modules()->disabled('warmup')) {
            return self::SUCCESS;
        }

        $count = 0;

        $waiting = WarmupMessage::query()
            ->select('recipient_id')
            ->where('status', 'sent')
            ->whereNull('delivered_at')
            ->where('sent_at', '>=', now()->subDays(3));

        EmailAccount::query()
            ->whereIn('status', [EmailAccountStatus::Active->value, EmailAccountStatus::Paused->value])
            ->whereIn('id', $waiting)
            ->select('id')
            ->chunkById(500, function ($mailboxes) use (&$count): void {
                foreach ($mailboxes as $mailbox) {
                    ProcessWarmupInbox::dispatch($mailbox->id);
                    $count++;
                }
            });

        $this->info("Queued {$count} warmup inbox checks.");

        return self::SUCCESS;
    }
}
