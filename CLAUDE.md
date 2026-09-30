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
- `app/Filament/Admin/**`: super admin resources, pages, widgets
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
- Everything customer-owned is scoped to a workspace (from Phase 1 onward).
- Keep `Model::shouldBeStrict()` passing: no lazy loading, no silent mass-assignment drops.
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
