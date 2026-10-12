<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Campaigns\AbTesting;
use Illuminate\Console\Command;

class DecideAbTests extends Command
{
    protected $signature = 'campaigns:ab-test';

    protected $description = 'Pick A/B test winners for active campaigns that have enough sends';

    public function handle(AbTesting $testing): int
    {
        $picked = 0;

        Campaign::query()
            ->where('status', CampaignStatus::Active->value)
            ->where('ab_auto_winner', true)
            ->whereHas('steps', fn ($steps) => $steps->whereNull('ab_winner')->whereHas('variants'))
            ->each(function (Campaign $campaign) use ($testing, &$picked): void {
                $picked += $testing->decide($campaign);
            });

        $this->components->info("Picked {$picked} A/B test winners.");

        return self::SUCCESS;
    }
}
