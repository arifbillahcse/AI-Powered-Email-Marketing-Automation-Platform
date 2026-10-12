<?php

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionManager;
use Illuminate\Console\Command;

class RunBilling extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'End trials, create renewal invoices, and mark unpaid subscriptions overdue or expired';

    public function handle(SubscriptionManager $subscriptions): int
    {
        if (! config('billing.enabled')) {
            $this->components->info('Billing is off (BILLING_ENABLED=false).');

            return self::SUCCESS;
        }

        $counts = $subscriptions->run();

        $this->components->info(sprintf(
            '%d trials ended, %d renewal invoices, %d overdue, %d expired.',
            $counts['trials_ended'], $counts['renewals'], $counts['past_due'], $counts['expired'],
        ));

        return self::SUCCESS;
    }
}
