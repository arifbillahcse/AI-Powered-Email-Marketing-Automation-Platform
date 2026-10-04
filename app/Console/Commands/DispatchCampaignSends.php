<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Sending\SendingDiagnostics;
use App\Services\Sending\SendScheduler;
use Illuminate\Console\Command;

class DispatchCampaignSends extends Command
{
    protected $signature = 'campaigns:send';

    protected $description = 'Queue the campaign emails that are due now (runs every minute)';

    public function handle(SendScheduler $scheduler, SendingDiagnostics $diagnostics): int
    {
        $queued = $scheduler->tick();

        $this->components->info("Queued {$queued} ".str('email')->plural($queued).'.');

        // Run by hand, explain what is holding each active campaign back.
        if ($queued === 0) {
            Campaign::query()
                ->where('status', CampaignStatus::Active->value)
                ->where('is_template', false)
                ->orderBy('id')
                ->each(function (Campaign $campaign) use ($diagnostics): void {
                    if ($reason = $diagnostics->headline($campaign)) {
                        $this->components->twoColumnDetail($campaign->name, $reason);
                    }
                });
        }

        return self::SUCCESS;
    }
}
