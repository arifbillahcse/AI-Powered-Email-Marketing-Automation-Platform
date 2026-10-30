# AI-Powered Email Marketing Automation Platform

A complete cold email outreach platform in the spirit of Instantly.ai: connect
unlimited mailboxes, warm them up, find and import leads, run multi-step
(and multichannel) sequences at a safe pace, personalize with AI, and handle
every reply from one inbox. Sell it as a SaaS with plans and payments, or run
it white-labelled for agency clients.

Built with **Laravel 12**, **Filament 5**, **PHP 8.4**, **PostgreSQL / MySQL**,
**Redis** and **Horizon**. Runs on a VPS (Docker) **and** on cPanel shared
hosting (no Redis, no Node.js, no long-running processes).

| Document | What's in it |
|---|---|
| **[docs/USER_MANUAL.md](docs/USER_MANUAL.md)** | The operating manual: every screen for users, agencies and the platform owner, server operations, troubleshooting |
| [docs/DEPLOY_CPANEL.md](docs/DEPLOY_CPANEL.md) | Step-by-step deployment on cPanel / shared hosting |
| [docs/API.md](docs/API.md) | REST API v1 and signed webhooks |
| [docs/STYLE_GUIDE.md](docs/STYLE_GUIDE.md) | Colors, typography and UI rules |
| [ROADMAP.md](ROADMAP.md) | The build plan, phase by phase (all 18 phases complete) |
| [CLAUDE.md](CLAUDE.md) | Code layout and conventions for contributors |

---

## Features

### Sending infrastructure
- **Mailboxes:** Google and Microsoft 365 sign-in (OAuth, no app passwords),
  any SMTP/IMAP server, Zoho presets, and CSV bulk import. Per-mailbox daily
  limit, random gap between emails, sending window, signature and custom
  tracking domain.
- **Domain health:** MX, SPF, DKIM and DMARC checks with copy-paste fixes,
  plus daily blacklist (DNSBL) monitoring.
- **Inbox warmup:** a warmup pool that exchanges natural emails, rescues them
  from spam, files them in a Warmup folder and replies; ramps up 2 a day to
  the target and reports a 7-day inbox rate per mailbox.
- **Inbox placement tests:** send a test to seed inboxes and see inbox, spam
  or missing per provider.

### Leads
- CSV and Excel import in the background (custom fields become
  `{{variables}}`), lists, rule-based segments, tags, activity timeline,
  bulk actions on any selection size, export.
- **Email verification** (built-in checks, optional MillionVerifier or
  ZeroBounce) and a **lead finder** (Hunter.io domain search).
- **AI enrichment** from each lead's website.
- Suppression list (emails and domains) fed automatically by unsubscribes,
  bounces and spam complaints.

### Campaigns
- Multi-step sequences with follow-ups in the same thread, `{{variables|fallbacks}}`
  and `{spin|tax}`, a spam checker, preview per lead and test sends.
- **Multichannel steps:** LinkedIn (view, connect, message), call and custom
  tasks that pause the sequence until done.
- **A/B/n testing** (versions A–E) with automatic winner selection.
- **Template library** of ready-made sequences, your own templates, and an
  **AI copilot** that writes a sequence from a brief.
- Sending engine: inbox rotation, sticky mailbox per lead, campaign and
  mailbox windows in their time zones, daily caps, never a step sent twice,
  and plain-language **Sending status** when a campaign isn't sending.
- Compliance built in: unsubscribe link, RFC 8058 one-click
  `List-Unsubscribe`, postal address footer, suppression re-checked right
  before every send.

### AI
- **AI personalization:** first lines, subjects or whole emails per lead, with
  a review queue (only approved content is sent).
- **Reply intelligence:** replies sorted (interested, meeting, not now...),
  summarized, leads auto-labelled, and AI-drafted answers.
- Included platform credits (Claude via the official Anthropic SDK) or
  bring-your-own Anthropic / OpenAI key, with token usage tracking.

### Replies and insights
- **Unibox:** every reply from every mailbox, out-of-office detection,
  threaded replies from the original mailbox.
- **Write to one lead:** a one-click composer on every lead (smart mailbox
  choice, greeting typed, Ctrl+Enter to send) with reusable **message
  templates**.
- **Organize leads:** notes (who you contacted, what's next), colored tags,
  a star for important leads, and follow-up dates with a morning reminder.
  Links in a lead's custom fields are clickable.
- **Analytics:** sent, open, click, reply, bounce and unsubscribe rates,
  daily chart, breakdowns per campaign, step and mailbox, mailbox health
  scores, CSV export.
- **Deliverability safety:** auto-pause of mailboxes and campaigns on high
  bounce or complaint rates, spam-complaint (ARF) processing, GDPR export and
  erasure per lead.

### Teams, agencies and integrations
- Workspaces with Owner, Admin, Member and read-only Client roles,
  invitations and two-factor authentication.
- **Agency / white label:** client workspaces on the agency's plan, own
  brand name, logo, color and custom domain, and branded client report links
  and scheduled report emails.
- **REST API** with scoped keys, and integrations: signed webhooks (Zapier,
  Make, n8n), Slack, Telegram, HubSpot, Pipedrive, Google Sheets.

### SaaS business
- Plans with limits and modules, free trials, invoices, renewals, grace
  periods; payments by **Stripe**, **SSLCommerz**, **bKash** or bank transfer
  (always re-verified with the gateway).
- **Super admin panel** (`/admin`): plans, workspaces, users (impersonate,
  suspend), invoices, abuse watch, dedicated sending, system health.
- Public **website**: landing page, pricing from your plans, help center,
  terms, privacy and sitemap. Dashboard onboarding checklist and a demo
  account generator for sales demos.

### Scale
- Horizon worker pools per workload, a dedicated `sending-priority` queue for
  big senders, read replicas (`DB_READ_HOST`), and archiving of old tracking
  events to compressed files.

---

## Quick start (Docker)

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app npm install
docker compose exec app npm run build
```

| URL | What |
|---|---|
| http://localhost:8000 | Public website (landing, pricing, help) |
| http://localhost:8000/app | Customer app |
| http://localhost:8000/admin | Super admin panel |
| http://localhost:8000/horizon | Queue dashboard (super admins) |
| http://localhost:8025 | Mailpit (catches all local email) |

Seeded accounts (password `password`):

| Email | Role |
|---|---|
| `demo@example.com` | Owner of "Demo Agency" and "Second Client Co" |
| `teammate@example.com` | Member of "Demo Agency" |
| `client@example.com` | Client (view-only) in "Demo Agency" |
| `admin@example.com` | Platform super admin (`/admin`) |

The seeded mailbox sends through Mailpit. Mailpit has no IMAP, so its
connection test reports an IMAP error; that's expected locally.

## Quick start (without Docker)

Requires PHP 8.4 (pdo_pgsql or pdo_mysql, intl, zip, gd, bcmath), Composer,
Node 22, PostgreSQL 16 (or MySQL 8) and Redis 7 (used by Horizon in `composer dev`).

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
composer dev   # server + Horizon + logs + vite
```

## Deploying to production

- **cPanel / shared hosting:** follow [docs/DEPLOY_CPANEL.md](docs/DEPLOY_CPANEL.md).
  GitHub Actions builds a ready-to-upload zip (Actions → "Package for cPanel").
  One cron job runs everything:

  ```bash
  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
  ```

- **VPS:** PostgreSQL + Redis, `php artisan horizon` under Supervisor, plus
  the same cron. See Part 5 of the [manual](docs/USER_MANUAL.md#part-5-server-operations).

After the first deploy:

```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan app:create-super-admin          # your /admin account
php artisan billing:install-plans           # sample plans (SaaS mode)
php artisan app:demo --email=demo@yourdomain.com --password='...'   # optional demo account
```

Updating later:

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear && php artisan optimize
```

---

## Configuration essentials

All settings live in `.env` (full reference in
[Appendix A of the manual](docs/USER_MANUAL.md#appendix-a-settings-reference-env)).

| Area | Key settings |
|---|---|
| App | `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false` |
| Database | `DB_CONNECTION` (`pgsql` or `mysql`), `DB_*`, optional `DB_READ_HOST` |
| Queues | `QUEUE_CONNECTION` (`database` on cPanel, `redis` on VPS) |
| System email | `MAIL_*` (invitations, password resets, client reports) |
| Mailbox sign-in | `GOOGLE_MAIL_CLIENT_ID/SECRET`, `MICROSOFT_MAIL_CLIENT_ID/SECRET/TENANT` |
| AI | `ANTHROPIC_API_KEY`, `AI_PLATFORM_MODEL`, `AI_PLATFORM_MONTHLY_TOKENS` |
| Data services | `EMAIL_VERIFICATION_PROVIDER/API_KEY`, `HUNTER_API_KEY`, `PLACEMENT_SEED_WORKSPACE` |
| Billing | `BILLING_ENABLED`, `STRIPE_*`, `SSLCOMMERZ_*`, `BKASH_*`, `BILLING_MANUAL_*` |
| Website | `MARKETING_SITE_ENABLED`, `MARKETING_COMPANY`, `MARKETING_CONTACT_EMAIL` |
| Deliverability | `AUTO_PAUSE_*`, `TRACKING_CNAME_TARGET`, `WARMUP_POOL` |

**Billing is off by default:** a self-hosted or custom-built install has no
plans or limits. Set `BILLING_ENABLED=true` to sell workspaces on plans.

### Feature modules

Optional features can be switched off for everyone (and, with billing on,
included per plan):

```env
MODULE_AI_ENABLED=true
MODULE_API_ENABLED=true
MODULE_WARMUP_ENABLED=true
MODULE_EMAIL_VERIFICATION_ENABLED=true
MODULE_WHITE_LABEL_ENABLED=true
MODULE_MULTICHANNEL_ENABLED=true
MODULE_INBOX_PLACEMENT_ENABLED=true
MODULE_LEAD_FINDER_ENABLED=true
```

A disabled module stays visible as locked ("Soon") rather than hidden.

---

## How it works

```
Mailboxes ──► Warmup pool ──► reputation
Leads ──► Lists / Segments ──► Campaign audience
                                   │
Every minute: campaigns:send ──► picks one due lead per available mailbox
                                   │   (windows, limits, gaps, suppression)
                                   ▼
                     sending queue ──► SMTP (mailbox's own server)
                                   │   tracking pixel / links / unsubscribe
Every 5 minutes: inbox:sync ──► IMAP ──► replies, bounces, complaints
                                   ▼
                     Unibox + AI reply intelligence ──► integrations / API
```

Scheduled jobs (all from the one cron): sending every minute, reply sync and
warmup every 5–10 minutes, A/B decisions and placement checks, nightly DNS and
blacklist checks, daily client reports, hourly billing and event archiving.
See [Appendix B of the manual](docs/USER_MANUAL.md#appendix-b-scheduled-jobs-and-queues).

Queues: `sending-priority`, `sending`, `imap`, `default`, `ai`, `imports`.

---

## Development

```bash
composer test         # Pest test suite (PostgreSQL or MySQL)
composer lint         # Pint (fix code style)
composer test:lint    # Pint check, as in CI
```

- Tests need PostgreSQL or MySQL (`DB_CONNECTION=pgsql` / `mysql`); some
  queries use syntax SQLite doesn't support.
- CI runs the full suite on **both PostgreSQL and MySQL** on every push.
- Code layout, conventions and rules for new features: [CLAUDE.md](CLAUDE.md).

## License

MIT
