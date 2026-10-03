<?php

namespace App\Console\Commands;

use App\Services\Sending\SendScheduler;
use Illuminate\Console\Command;

class DispatchCampaignSends extends Command
{
    protected $signature = 'campaigns:send';

    protected $description = 'Queue the campaign emails that are due now (runs every minute)';

    public function handle(SendScheduler $scheduler): int
    {
        $queued = $scheduler->tick();

        if ($queued > 0 || $this->output->isVerbose()) {
            $this->components->info("Queued {$queued} emails.");
        }

        return self::SUCCESS;
    }
}
