<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mailbox defaults and limits
    |--------------------------------------------------------------------------
    |
    | Conservative defaults protect new mailboxes' sender reputation. Most
    | providers flag cold outreach above ~50 emails per inbox per day.
    |
    */

    'mailboxes' => [
        'default_daily_limit' => 30,
        'max_daily_limit' => 200,
        'default_min_delay_seconds' => 90,
        'default_max_delay_seconds' => 300,
        'min_delay_floor_seconds' => 30,
        'default_send_window' => ['09:00', '17:00'],
        'default_send_days' => [1, 2, 3, 4, 5], // ISO weekdays, Mon-Fri

        // Seconds before an SMTP/IMAP connection test gives up.
        'connect_timeout' => (int) env('MAILBOX_CONNECT_TIMEOUT', 10),

        // Users type SMTP/IMAP hosts, and the server connects to them. Keep
        // this false in production so nobody can probe your internal network.
        // Local dev with Mailpit needs it true.
        'allow_private_hosts' => (bool) env('MAILBOX_ALLOW_PRIVATE_HOSTS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain health checks
    |--------------------------------------------------------------------------
    */

    'dns' => [
        // Tried in order when a domain has no DKIM selector set.
        'dkim_selectors' => [
            'google', 'selector1', 'selector2', 'default', 'dkim', 'k1', 's1', 's2',
            'mail', 'zoho', 'zmail', 'smtp', 'mxvault',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom tracking domains
    |--------------------------------------------------------------------------
    |
    | Customers point a CNAME (e.g. track.theirdomain.com) at this host so
    | open/click tracking links use their own domain instead of ours.
    |
    */

    'tracking' => [
        'cname_target' => env('TRACKING_CNAME_TARGET', 'track.'.parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sending engine
    |--------------------------------------------------------------------------
    */

    'sending' => [
        // A lead picked for sending is held this long; if its job never runs
        // (worker down) it becomes due again. A step is never sent twice.
        'lease_minutes' => 30,
        // Wait before retrying after a temporary SMTP error.
        'retry_minutes' => 15,
        // URL scheme for custom tracking domains (they need an SSL
        // certificate for https; see the tracking domain help text).
        'tracking_scheme' => env('TRACKING_SCHEME', 'https'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue processing
    |--------------------------------------------------------------------------
    |
    | With Redis + Horizon (VPS/Docker), Horizon runs the workers. On shared
    | hosting (cPanel) there's no Redis and no long-running processes, so set
    | QUEUE_RUN_FROM_SCHEDULER=true: the every-minute cron then works the
    | queues (database driver) for up to ~55 seconds per run.
    |
    */

    'queue' => [
        'run_from_scheduler' => (bool) env('QUEUE_RUN_FROM_SCHEDULER', false),
        // Highest priority first.
        'queues' => 'sending,imap,default,ai,imports',
    ],

];
