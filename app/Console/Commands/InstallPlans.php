<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;

/**
 * Starter plans to edit in Super admin → Plans. Existing plans (by slug)
 * are left alone.
 */
class InstallPlans extends Command
{
    protected $signature = 'billing:install-plans';

    protected $description = 'Create the sample Starter, Growth and Agency plans';

    public function handle(): int
    {
        $plans = [
            ['name' => 'Starter', 'slug' => 'starter', 'is_default' => true, 'sort' => 1,
                'description' => 'For founders and freelancers starting cold outreach.',
                'price_monthly' => 2900, 'price_yearly' => 29000,
                'limits' => ['mailboxes' => 3, 'leads' => 5000, 'emails_per_month' => 6000, 'team_members' => 2],
                'modules' => ['ai']],
            ['name' => 'Growth', 'slug' => 'growth', 'sort' => 2,
                'description' => 'For teams scaling outreach across many inboxes.',
                'price_monthly' => 7900, 'price_yearly' => 79000,
                'limits' => ['mailboxes' => 15, 'leads' => 50000, 'emails_per_month' => 50000, 'team_members' => 5],
                'modules' => ['ai', 'email_verification', 'api']],
            ['name' => 'Agency', 'slug' => 'agency', 'sort' => 3,
                'description' => 'For agencies running outreach for clients.',
                'price_monthly' => 19900, 'price_yearly' => 199000,
                'limits' => ['mailboxes' => null, 'leads' => 250000, 'emails_per_month' => 250000, 'team_members' => null, 'clients' => 25],
                'modules' => ['ai', 'email_verification', 'api', 'warmup', 'white_label']],
        ];

        $created = 0;

        foreach ($plans as $data) {
            $plan = Plan::query()->firstOrNew(['slug' => $data['slug']]);

            if (! $plan->exists) {
                $plan->fill(['currency' => 'USD', 'trial_days' => 14, 'is_active' => true, ...$data])->save();
                $created++;
            }
        }

        $this->components->info("Created {$created} plans. Edit prices and limits in Super admin → Plans.");

        return self::SUCCESS;
    }
}
