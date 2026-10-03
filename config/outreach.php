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
    | Unibox (reply detection)
    |--------------------------------------------------------------------------
    |
    | Mailboxes are polled over IMAP every few minutes. Only replies to
    | campaign emails (and bounces) are imported; other mail is left alone.
    |
    */

    'inbox' => [
        'messages_per_run' => 50,
        'seconds_per_run' => 35, // stay well under the 50-second job limit
        'max_message_bytes' => 262_144,
        'lookback_days' => 30, // first sync: how far back to look for replies

        // Subjects of automatic replies (regex, case-insensitive).
        'auto_reply_subjects' => [
            '^(re:\s*)?auto(matic)?[\s_-]*(reply|response|antwort)',
            'out of (the )?office',
            'away from (the |my )?(office|desk)',
            '\bon (vacation|holiday|leave|annual leave)\b',
            '^(re:\s*)?autoreply',
            'abwesenheit',
            'automatische antwort',
            'r[ée]ponse automatique',
            'absence du bureau',
            'respuesta autom[áa]tica',
            'fuera de la oficina',
            'risposta automatica',
            'fuori sede',
            'resposta autom[áa]tica',
            'afwezig',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI personalization
    |--------------------------------------------------------------------------
    |
    | "Included AI credits" use the platform's own Anthropic key with a monthly
    | token allowance per workspace. Workspaces can bring their own Anthropic
    | or OpenAI key instead (no allowance applies).
    |
    */

    'ai' => [
        'platform_api_key' => env('ANTHROPIC_API_KEY'),
        'platform_model' => env('AI_PLATFORM_MODEL', 'claude-opus-5-5'),
        'platform_monthly_tokens' => (int) env('AI_PLATFORM_MONTHLY_TOKENS', 2_000_000),
        'timeout' => (float) env('AI_TIMEOUT', 40),
        // Leads whose email needs AI content that isn't approved yet are
        // checked again after this many minutes.
        'awaiting_review_minutes' => 60,
        'max_retries' => 0, // the queue retries with backoff instead (jobs must stay under 50s)

        // Claude models offered in settings, with $ per million tokens for
        // cost estimates (input, output, cache read).
        'claude_models' => [
            'claude-opus-5-5' => ['label' => 'Claude Opus 5.5 (best quality)', 'input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20],
            'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5 (balanced)', 'input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20],
            'claude-haiku-4-5' => ['label' => 'Claude Haiku 4.5 (fastest, cheapest)', 'input' => 1.00, 'output' => 5.00, 'cache_read' => 0.10],
        ],
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
