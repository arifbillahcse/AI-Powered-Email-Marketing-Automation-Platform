<?php

namespace App\Console\Commands;

use App\Jobs\CheckSendingDomain;
use App\Models\SendingDomain;
use Illuminate\Console\Command;

class CheckSendingDomains extends Command
{
    protected $signature = 'domains:check {--all : Re-check every domain, not just stale ones}';

    protected $description = 'Queue DNS health checks (MX, SPF, DKIM, DMARC) for sending domains';

    public function handle(): int
    {
        $count = 0;

        SendingDomain::query()
            ->when(! $this->option('all'), fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<', now()->subHours(20))))
            ->chunkById(200, function ($domains) use (&$count): void {
                foreach ($domains as $domain) {
                    CheckSendingDomain::dispatch($domain);
                    $count++;
                }
            });

        $this->components->info("Queued {$count} domain checks.");

        return self::SUCCESS;
    }
}
