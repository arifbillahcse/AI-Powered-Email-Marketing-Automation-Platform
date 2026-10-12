<?php

namespace App\Console\Commands;

use App\Jobs\CheckDomainBlacklists;
use App\Models\SendingDomain;
use Illuminate\Console\Command;

class CheckBlacklists extends Command
{
    protected $signature = 'domains:blacklists';

    protected $description = 'Queue blacklist (DNSBL) checks for every sending domain that has mailboxes';

    public function handle(): int
    {
        $count = 0;

        SendingDomain::query()
            ->whereHas('emailAccounts')
            ->select('id')
            ->chunkById(200, function ($domains) use (&$count): void {
                foreach ($domains as $domain) {
                    CheckDomainBlacklists::dispatch($domain->getKey());
                    $count++;
                }
            });

        $this->components->info("Queued {$count} blacklist checks.");

        return self::SUCCESS;
    }
}
