<?php

namespace App\Console\Commands;

use App\Jobs\SendWarmupEmail;
use App\Models\EmailAccount;
use App\Services\Warmup\WarmupPlanner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SendWarmupEmails extends Command
{
    protected $signature = 'warmup:send';

    protected $description = 'Queue the warmup emails that are due (one per mailbox per run, spread over the day)';

    public function handle(WarmupPlanner $planner): int
    {
        if (modules()->disabled('warmup')) {
            return self::SUCCESS;
        }

        $queued = 0;
        $due = fn (Builder $query) => $query->whereNull('warmup_next_at')->orWhere('warmup_next_at', '<=', now());

        $planner->pool()->where($due)->chunkById(200, function ($mailboxes) use ($planner, $due, &$queued): void {
            /** @var EmailAccount $mailbox */
            foreach ($mailboxes as $mailbox) {
                if (! $planner->allowed($mailbox)) {
                    continue;
                }

                // Warmup switched on before the module was: start the ramp now.
                if (! $mailbox->warmup_started_at) {
                    $mailbox->forceFill(['warmup_started_at' => now()])->save();
                }

                $remaining = $planner->quota($mailbox) - $planner->startedToday($mailbox);

                // Claim the mailbox so overlapping runs can't queue it twice.
                $claimed = EmailAccount::query()
                    ->whereKey($mailbox->getKey())
                    ->where($due)
                    ->update(['warmup_next_at' => $planner->nextSendAt($remaining - 1)]);

                if ($claimed === 1 && $remaining > 0) {
                    SendWarmupEmail::dispatch($mailbox->getKey());
                    $queued++;
                }
            }
        });

        $this->info("Queued {$queued} warmup emails.");

        return self::SUCCESS;
    }
}
