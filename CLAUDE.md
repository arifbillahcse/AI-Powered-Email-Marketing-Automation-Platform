# CLAUDE.md

Instructions for Claude Code sessions working on this repo.

## Project
AI-powered cold email outreach platform (Instantly.ai-style). The build plan is in
`ROADMAP.md`. **Build one phase at a time**, in order, and don't start the next
phase unless asked. Update the phase's status in `ROADMAP.md` when it's done.

## Stack
- PHP 8.4, Laravel 12, Filament 5 (Livewire 4, Tailwind 4)
- PostgreSQL 16 (primary DB), Redis 7 (cache, sessions, queues)
- Laravel Horizon for queues, Pest 4 for tests, Pint for style
- Docker for local dev (`docker-compose.yml`)

## Layout
- `app/Providers/Filament/AppPanelProvider.php`: customer panel at `/app`
- `app/Providers/Filament/AdminPanelProvider.php`: super admin panel at `/admin`
- `app/Filament/App/**`: customer resources, pages, widgets
- `app/Filament/App/Tenancy/`: workspace create + settings pages (kept out of
  `Pages/` so discovery doesn't register them twice)
- `app/Filament/App/Auth/`: customised auth pages (sign-up)
- `app/Filament/Admin/**`: super admin resources, pages, widgets
- `app/Models/Workspace.php`: the tenant; `Membership` (pivot with role),
  `WorkspaceInvitation` (hashed token, 7-day expiry)
- `app/Services/Workspaces/TeamManager.php`: all team rules (invite, roles,
  remove, leave, accept). UI and tests go through it.
- `app/Enums/WorkspaceRole.php`: Owner, Admin, Member, Client
- `app/Models/EmailAccount.php` (mailboxes) and `SendingDomain.php` (DNS health)
- `app/Services/Mail/`: `MailboxTransportFactory` (the one place SMTP transports
  are built; Phase 5 sends through it), `MailboxConnectionTester`, `Imap/ImapProbe`,
  `HostGuard` (blocks private/internal hosts)
- `app/Services/Dns/`: `DnsResolver` interface (fake it in tests with
  `Tests\Fakes\FakeDnsResolver`), `DomainHealthChecker`, `TrackingDomainVerifier`
- `app/Policies/Concerns/AuthorizesWorkspaceRecords.php`: default policy for
  workspace-owned records (members view, everyone but Clients edit)
- `app/Models/Lead.php` (+ `LeadList`, `Tag`, `Segment`, `LeadActivity`, `Suppression`)
- `app/Services/Leads/`: `SuppressionList` (do-not-contact; sending must use
  `Lead::whereNotSuppressed()`), `SegmentQuery` (segment rules → query),
  `LeadBulkOperations` (chunked list/tag changes for huge selections)
- `app/Filament/Imports/LeadImporter.php`, `app/Filament/Exports/LeadExporter.php`
- `app/Filament/App/Resources/Concerns/ScopedToWorkspace.php`: every
  workspace-owned resource must use it (see Conventions)
- `app/Models/Campaign.php` (+ `CampaignStep`, `CampaignLead` = a lead's progress
  in a campaign; the sending engine works through `campaign_leads`)
- `app/Services/Campaigns/`: `TemplateRenderer` ({{variables|fallback}} +
  spintax), `CampaignMessageBuilder` (the exact email a lead gets; Phase 5
  sends with it), `CampaignAudience`, `CampaignLauncher`, `CampaignCloner`
- `app/Services/Sending/`: `SendScheduler` (every minute: picks + claims due
  leads per mailbox), `SendWindow`, `TrackingUrls` (signed click links),
  `LinkTracker`, `EngagementRecorder` (opens/clicks/unsubscribes/bounces),
  `SmtpFailure`, `BounceClassifier` (DSN parser for Phase 7)
- `app/Services/Sending/SendingDiagnostics.php`: why a campaign isn't sending (mirrors
  SendScheduler's rules; keep the two in sync)
- `app/Filament/App/Actions/SpreadsheetImportAction.php`: CSV import that also takes .xlsx
  (`Services/Leads/SpreadsheetConverter` turns the first sheet into CSV)
- `app/Jobs/SendCampaignEmail.php`: sends one step to one lead; pinned to a
  step number, and `email_messages` is unique per (campaign lead, step)
- `routes/tracking.php`: open pixel, click redirect, unsubscribe (no session/CSRF)
- `app/Services/Mail/Imap/ImapClient.php`: login/EXAMINE/UID SEARCH/FETCH over
  `ImapStream` (fake it with `Tests\Fakes\ScriptedImapStream`)
- `app/Services/Inbox/`: `InboxSynchronizer` (IMAP → processor, resumes from
  the last UID), `InboundMailProcessor` (bounce / reply / ignore; only
  campaign-related mail is stored), `ParsedEmail` (MIME via
  zbateson/mail-mime-parser), `AutoReplyDetector`, `InboxReplier` (Unibox
  replies, and `compose()` for one-off emails to a lead: a thread with no
  campaign; UI in `Filament/App/Actions/SendLeadEmailAction.php`)
- `app/Models/InboxThread.php` (one Unibox conversation per campaign lead) and
  `InboxMessage` (plain-text bodies; never render email HTML)
- `app/Jobs/SyncMailboxInbox.php` (`imap` queue, every 5 min via `inbox:sync`),
  `SendInboxReply.php` (`sending` queue, not retried)
- `app/Services/Ai/`: `TextGeneratorFactory` → `ClaudeGenerator` (official
  Anthropic SDK; tests inject a fake PSR-18 client or use `fakeAi()`) or
  `OpenAiGenerator`; `PromptBuilder` (lead data is untrusted input);
  `AiGenerationService` (review queue; only *approved* content is sent)
- `app/Models/AiSetting.php` (encrypted BYOK key), `AiPromptTemplate`,
  `AiGeneration` (one per lead per step per type), `AiUsage` (tokens)
- `app/Jobs/DispatchAiGenerations.php` + `GenerateAiContent.php` (`ai` queue)
- `app/Services/Analytics/`: `AnalyticsReport` (KPIs/breakdowns/daily, all from
  `email_messages` timestamps: sent_at, opened_at, clicked_at, replied_at,
  bounced_at, unsubscribed_at; keep each set once by its event),
  `AnalyticsFilters` (period in the workspace time zone), `MailboxHealth`, `AnalyticsCsv`
- `app/Filament/App/Pages/Analytics.php` (filters + widgets in
  `Widgets/Analytics/`) and `Pages/Dashboard.php` (explicit widget list)
- `config/outreach.php`: mailbox limits, DKIM selectors, tracking CNAME target, AI models/prices
- `config/modules.php` + `app/Support/Modules/ModuleRegistry.php`: feature flags
- `resources/css/filament/app/theme.css`: customer panel theme
- `docs/STYLE_GUIDE.md`: colors, typography, component rules

## Hosting targets
The app must run on **both** Docker/VPS (PostgreSQL + Redis + Horizon) and
**cPanel shared hosting** (MySQL/MariaDB, database queue/cache/sessions, no
Redis, no long-running processes, no Node.js). CI tests PostgreSQL and MySQL.
- No database-specific SQL; use the query builder (both CI legs must pass).
- JSON columns: use `$table->jsonb()`. On PostgreSQL plain `json` can't be
  compared, so `SELECT DISTINCT` (used by Filament relationship selects) fails.
- No Redis-only features. Rate limiting via `RateLimiter`/`Cache` works on both.
- Queued jobs must finish well under 50 seconds (cron works the queue for ~55s
  per minute on cPanel) and be safe to retry. Split long work into small jobs.
- Deployment guide: `docs/DEPLOY_CPANEL.md`.

## Conventions
- Follow the style guide for colors and status badges. Use the semantic Filament
  colors (`primary`, `success`, `warning`, `danger`, `info`, `gray`), never raw hex in PHP.
- Customer navigation groups: `Outreach`, `Infrastructure`, `Insights`, `Settings`.
- Optional features go behind a module in `config/modules.php`. Check with
  `modules()->enabled('name')`. Disabled modules show as locked, not hidden.
- Queues: `default`, `sending`, `imap`, `ai`, `imports` (see `config/horizon.php`).
  Put jobs on the right queue; never send email synchronously in a request.
- Encrypt stored secrets (mailbox passwords, API keys) with the `encrypted` cast.
- Everything customer-owned is scoped to a workspace: give the model a
  `workspace_id` and a `workspace()` BelongsTo (Filament tenancy uses it to scope
  resources). Get the current one with `Filament::getTenant()`.
- Every workspace-owned Filament resource uses the `ScopedToWorkspace` trait.
  Filament's own tenant scope is a runtime global scope that doesn't exist in
  queued jobs, so a queued export would otherwise include every workspace.
- Code that runs in jobs (imports, exports, sending) has no current tenant:
  always filter by `workspace_id` explicitly there.
- Bulk actions on leads use `->fetchSelectedRecords(false)` and work on the
  selection query in chunks; never load a whole selection into memory.
- Raw SQL that must run on both databases: in an `INSERT ... SELECT`, PostgreSQL
  can't type bound parameters or bare NULLs in the SELECT list. Inline integers
  (cast first), quote strings with the PDO `quote()`, and omit NULL columns.
- Never render user/email HTML directly in the app: previews go in a
  `sandbox=""` iframe.
- Log user-visible lead changes on the timeline with `$lead->logActivity()`.
- Roles: check `$user->roleIn($workspace)` with `canWrite()` (everyone but
  Client) for create/edit actions, and `canManageTeam()` (Owner/Admin) for
  settings and team. Policies wrap these; the Client role is read-only everywhere.
- Ownership is never assigned through invites or role changes.
- Models with DB column defaults must mirror them in `$attributes`, or strict
  mode throws when a freshly created model reads them.
- Eager load relations used by table columns and actions (lazy loading throws).
- Keep `Model::shouldBeStrict()` passing: no lazy loading, no silent mass-assignment drops.
- Every SMTP/IMAP connection to a user-supplied host goes through `HostGuard`.
  Never connect to a user-supplied host without it (SSRF).
- Never put mailbox passwords in form state, logs, notifications or `toArray()`.
- Long or user-waited work that talks to the outside world (sending, DNS at
  scale) goes on a queue and reports back with a Filament database notification.
- Sending must never send a step twice: claim leads with a conditional
  UPDATE, pin jobs to a step, and rely on the unique (campaign_lead_id,
  step_position) row. Re-check suppression in the job right before sending.
- Timestamps written by raw SQL use the app clock (`now()`), never the
  database's CURRENT_TIMESTAMP (tests travel in time; clocks can differ).
- Compliance is not optional: every campaign email gets an unsubscribe link,
  `List-Unsubscribe` headers, and a suppression check before sending.

## Commands
```bash
composer test           # php artisan test (Pest)
composer lint           # pint (fix)
composer test:lint      # pint --test (CI check)
php artisan app:create-super-admin
```

## Before committing
1. `composer test` passes
2. `composer test:lint` passes
3. New behavior has Pest tests (feature tests for panels, unit tests for services)
