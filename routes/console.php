<?php

use Illuminate\Support\Facades\Schedule;

// Horizon metrics for the queue dashboard (Redis setups only).
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->when(fn (): bool => config('queue.default') === 'redis');

// Campaign sending: queue due emails. Runs before the cPanel queue worker
// below, so on shared hosting the same cron run also sends them.
Schedule::command('campaigns:send')->everyMinute()->withoutOverlapping(5);

// Reply detection: check every mailbox for replies and bounces (imap queue).
Schedule::command('inbox:sync')->everyFiveMinutes()->withoutOverlapping(10);

// Shared hosting (cPanel): no long-running workers, so the cron-driven
// scheduler works the queues for just under a minute, every minute.
Schedule::command('queue:work', [
    '--queue='.config('outreach.queue.queues'),
    '--stop-when-empty',
    '--max-time=55',
    '--tries=3',
    '--timeout=50',
])
    ->everyMinute()
    ->withoutOverlapping(2)
    ->when(fn (): bool => (bool) config('outreach.queue.run_from_scheduler'));

// Housekeeping.
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('model:prune')->daily();

// Re-check sending domains' DNS so broken SPF/DKIM/DMARC is caught early.
Schedule::command('domains:check')->dailyAt('03:00');
