<?php

namespace App\Console\Commands;

use App\Jobs\SendClientReport;
use App\Models\Workspace;
use App\Services\Agency\ClientReport;
use Illuminate\Console\Command;

class SendClientReports extends Command
{
    protected $signature = 'reports:send';

    protected $description = 'Email the weekly and monthly client reports that are due today';

    public function handle(ClientReport $reports): int
    {
        if (modules()->disabled('white_label')) {
            return self::SUCCESS;
        }

        $queued = 0;

        Workspace::query()
            ->whereIn('report_frequency', ['weekly', 'monthly'])
            ->whereNotNull('report_recipients')
            ->chunkById(200, function ($workspaces) use ($reports, &$queued): void {
                foreach ($workspaces as $workspace) {
                    if (! $reports->due($workspace)) {
                        continue;
                    }

                    // Claim it first, so an overlapping run can't send it twice.
                    $claimed = Workspace::query()
                        ->whereKey($workspace->getKey())
                        ->where(fn ($query) => $query->whereNull('report_last_sent_at')->orWhere('report_last_sent_at', '<', now()->subHours(20)))
                        ->update(['report_last_sent_at' => now()]);

                    if ($claimed === 1) {
                        SendClientReport::dispatch($workspace->getKey());
                        $queued++;
                    }
                }
            });

        $this->info("Queued {$queued} client reports.");

        return self::SUCCESS;
    }
}
