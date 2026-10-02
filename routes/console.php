<?php

use Illuminate\Support\Facades\Schedule;

// Horizon metrics for the queue dashboard.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Housekeeping.
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('model:prune')->daily();

// Re-check sending domains' DNS so broken SPF/DKIM/DMARC is caught early.
Schedule::command('domains:check')->dailyAt('03:00');
