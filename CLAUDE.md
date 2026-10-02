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
- `config/outreach.php`: mailbox limits, DKIM selectors, tracking CNAME target
- `config/modules.php` + `app/Support/Modules/ModuleRegistry.php`: feature flags
- `resources/css/filament/app/theme.css`: customer panel theme
- `docs/STYLE_GUIDE.md`: colors, typography, component rules

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
