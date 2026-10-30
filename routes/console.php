<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Horizon metrics for the queue dashboard (Redis setups only).
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->when(fn (): bool => config('queue.default') === 'redis');

// Campaign sending: queue due emails. Runs before the cPanel queue worker
// below, so on shared hosting the same cron run also sends them.
Schedule::command('campaigns:send')->everyMinute()->withoutOverlapping(5);

// A/B tests: pick winners once every version has enough sends.
Schedule::command('campaigns:ab-test')->everyTenMinutes()->withoutOverlapping(10);

// Reply detection: check every mailbox for replies and bounces (imap queue).
Schedule::command('inbox:sync')->everyFiveMinutes()->withoutOverlapping(10);

// Inbox warmup: send due warmup emails, then handle the ones that arrived
// (open, rescue from spam, file away, reply).
Schedule::command('warmup:send')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('warmup:sync')->everyTenMinutes()->withoutOverlapping(10);

// Inbox placement tests: look for test emails in the seed mailboxes.
Schedule::command('placement:check')->everyFiveMinutes()->withoutOverlapping(10);

// Archive raw open/click events older than ARCHIVE_EVENTS_AFTER_DAYS.
Schedule::command('events:archive')->hourly()->withoutOverlapping(30);

// Lead follow-ups that came due (announced in each workspace's morning).
Schedule::command('leads:follow-ups')->hourly()->withoutOverlapping(10);

// Agencies: weekly (Mondays) and monthly (1st) client report emails.
Schedule::command('reports:send')->dailyAt('07:00');

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

// Blacklist (DNSBL) monitoring for sending domains and their mail servers.
Schedule::command('domains:blacklists')->dailyAt('04:00');

// SaaS billing: trials, renewal invoices, overdue and expired subscriptions.
Schedule::command('billing:run')->hourly()->when(fn (): bool => (bool) config('billing.enabled'));

// Heartbeat for Super admin → System health ("is the cron running?").
Schedule::call(fn () => Cache::put('system:scheduler_heartbeat', now()->toIso8601String(), now()->addDay()))
    ->everyMinute()
    ->name('scheduler-heartbeat');
