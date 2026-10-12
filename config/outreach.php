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
        // Mailboxes are open all day by default: each campaign has its own
        // schedule, and both must be open to send. Narrow a mailbox's window
        // only to restrict that mailbox.
        'default_send_window' => ['00:00', '23:59'],
        'default_send_days' => [1, 2, 3, 4, 5, 6, 7], // ISO weekdays

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
    | Email verification (`email_verification` module)
    |--------------------------------------------------------------------------
    |
    | Built in: syntax, the domain's mail servers (MX), disposable and role
    | addresses. Whether the mailbox itself exists needs a verification
    | service (MillionVerifier or ZeroBounce): we never probe SMTP servers
    | ourselves, which gets IPs blacklisted and port 25 is usually blocked.
    |
    */

    'verification' => [
        'provider' => env('EMAIL_VERIFICATION_PROVIDER'), // millionverifier | zerobounce | null
        'api_key' => env('EMAIL_VERIFICATION_API_KEY'),
        'seconds_per_job' => 35,
        'role_accounts' => [
            'admin', 'administrator', 'billing', 'careers', 'contact', 'enquiries', 'help', 'hello', 'hr', 'info',
            'inquiries', 'jobs', 'marketing', 'media', 'news', 'no-reply', 'noreply', 'office', 'postmaster',
            'press', 'privacy', 'sales', 'security', 'support', 'team', 'webmaster',
        ],
        'disposable_domains' => [
            '10minutemail.com', '20minutemail.com', 'dispostable.com', 'emailondeck.com', 'fakeinbox.com',
            'getairmail.com', 'getnada.com', 'guerrillamail.com', 'guerrillamail.net', 'mailcatch.com',
            'maildrop.cc', 'mailinator.com', 'mailnesia.com', 'mintemail.com', 'mohmal.com', 'mytemp.email',
            'sharklasers.com', 'spamgourmet.com', 'temp-mail.org', 'tempmail.com', 'tempmail.net',
            'tempmailo.com', 'throwawaymail.com', 'trashmail.com', 'yopmail.com', 'yopmail.net',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deliverability safety (auto-pause)
    |--------------------------------------------------------------------------
    |
    | A mailbox or campaign whose bounce or spam-complaint rate goes over
    | these limits is paused automatically, before it damages the domain.
    | Gmail and Yahoo require complaints under 0.3%; bounces over ~5% mean
    | an unverified list. Rates count emails sent in the last `window_days`
    | (and only after the last manual resume), once at least `min_sent`
    | have gone out.
    |
    */

    'safety' => [
        'enabled' => (bool) env('AUTO_PAUSE_ENABLED', true),
        'window_days' => 7,
        'min_sent' => 20,
        'max_bounce_rate' => (float) env('AUTO_PAUSE_BOUNCE_RATE', 5.0), // percent
        'max_complaint_rate' => (float) env('AUTO_PAUSE_COMPLAINT_RATE', 0.3), // percent
    ],

    /*
    |--------------------------------------------------------------------------
    | Spam-word checker
    |--------------------------------------------------------------------------
    |
    | Phrases that spam filters (and readers) associate with bulk mail.
    | Matched as whole words, case-insensitively.
    |
    */

    'spam' => [
        'words' => [
            '100% free', '100% satisfied', 'act now', 'apply now', 'as seen on', 'bargain', 'best price',
            'big bucks', 'billion', 'buy now', 'call now', 'cash bonus', 'cheap', 'click below', 'click here',
            'congratulations', 'dear friend', 'double your', 'earn money', 'extra income', 'fast cash',
            'free access', 'free gift', 'free money', 'free trial', 'get paid', 'guarantee', 'guaranteed',
            'increase sales', 'incredible deal', 'limited time', 'lowest price', 'make money', 'million dollars',
            'miracle', 'no catch', 'no cost', 'no credit check', 'no obligation', 'no strings attached',
            'not spam', 'offer expires', 'once in a lifetime', 'order now', 'risk-free', 'risk free',
            'special promotion', 'this is not spam', 'unlimited', 'urgent', 'winner', 'you have been selected',
            'work from home', '$$$', 'cash', 'crypto', 'viagra', 'casino', 'loan', 'prize',
        ],
        'url_shorteners' => [
            'bit.ly', 'tinyurl.com', 'goo.gl', 't.co', 'ow.ly', 'is.gd', 'buff.ly', 'rebrand.ly', 'cutt.ly', 'shorturl.at', 'rb.gy',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Blacklist (DNSBL) monitoring
    |--------------------------------------------------------------------------
    |
    | Sending domains are checked against domain blocklists, and the IPs of
    | their mailboxes' SMTP servers against IP blocklists, once a day.
    | Spamhaus refuses queries from big public resolvers (8.8.8.8 etc.);
    | those answers are reported as "couldn't check", never as listed.
    |
    */

    'blacklists' => [
        'domains' => [
            'dbl.spamhaus.org' => 'Spamhaus DBL',
            'multi.surbl.org' => 'SURBL',
            'multi.uribl.com' => 'URIBL',
        ],
        'ips' => [
            'zen.spamhaus.org' => 'Spamhaus ZEN',
            'bl.spamcop.net' => 'SpamCop',
            'b.barracudacentral.org' => 'Barracuda',
            'psbl.surriel.com' => 'PSBL',
        ],
        'max_ips' => 5,
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
        // On by default with the database queue (shared hosting has no
        // long-running workers); Redis setups use Horizon instead.
        'run_from_scheduler' => (bool) env('QUEUE_RUN_FROM_SCHEDULER', env('QUEUE_CONNECTION', 'database') === 'database'),
        // Highest priority first.
        'queues' => 'sending,imap,default,ai,imports',
    ],

];
