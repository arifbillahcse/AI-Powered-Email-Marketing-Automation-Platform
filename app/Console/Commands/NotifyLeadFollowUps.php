<?php

namespace App\Console\Commands;

use App\Services\Leads\FollowUps;
use Illuminate\Console\Command;

class NotifyLeadFollowUps extends Command
{
    protected $signature = 'leads:follow-ups';

    protected $description = 'Notify people about lead follow-ups that are due (once each, in the workspace\'s morning)';

    public function handle(FollowUps $followUps): int
    {
        $this->components->info("Announced {$followUps->notifyDue()} due follow-ups.");

        return self::SUCCESS;
    }
}
