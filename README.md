# AI-Powered Email Marketing Automation Platform

Cold email outreach platform with AI personalization, in the spirit of Instantly.ai:
connect mailboxes, import leads, run multi-step sequences with per-inbox throttling,
personalize with AI, and manage replies from one inbox.

Built with **Laravel 12**, **Filament 5**, **PostgreSQL**, **Redis** and **Horizon**.
See [ROADMAP.md](ROADMAP.md) for the phase-by-phase build plan and
[docs/STYLE_GUIDE.md](docs/STYLE_GUIDE.md) for the design system.

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
| http://localhost:8000/app | Customer app (seeded below) |
| http://localhost:8000/admin | Super admin (seeded: `admin@example.com` / `password`) |
| http://localhost:8000/horizon | Queue dashboard (super admins) |
| http://localhost:8025 | Mailpit (catches all local email) |

Seeded accounts (password `password` for all):

| Email | Role |
|---|---|
| `demo@example.com` | Owner of "Demo Agency" and "Second Client Co" |
| `teammate@example.com` | Member of "Demo Agency" |
| `client@example.com` | Client (view-only) in "Demo Agency" |
| `admin@example.com` | Platform super admin (`/admin`) |

Create a real super admin with `php artisan app:create-super-admin`.

## Workspaces and roles

Every customer works inside a **workspace** (`/app/{workspace}`), and can belong
to several and switch between them from the sidebar menu.

| Role | Can do |
|---|---|
| Owner | Everything. Can't be removed or leave. |
| Admin | Manage team, roles and workspace settings, plus everything a Member can |
| Member | Run campaigns, manage leads, reply |
| Client | View only |

## Email accounts and domains

Infrastructure → **Email accounts** connects mailboxes over SMTP/IMAP (Google,
Microsoft 365 and Zoho presets, or any custom server). Saving runs a connection
test; "Send test email" sends a real message and reports back in the bell menu.
Each mailbox has its own daily limit, random gap between emails, send window
and days, signature and optional custom tracking domain.

Infrastructure → **Domains** checks MX, SPF, DKIM and DMARC for every sending
domain (daily, and on demand) and shows copy-paste DNS records to fix problems.

Locally, the seeded mailbox sends through Mailpit. Mailpit has no IMAP, so its
connection test reports an IMAP error; that's expected.

Invitations are emailed (queued) and expire after 7 days. Users can turn on
two-factor authentication (authenticator app + recovery codes) from their profile.

## Deploying on cPanel / shared hosting

No Docker, Redis or Node.js needed: see **[docs/DEPLOY_CPANEL.md](docs/DEPLOY_CPANEL.md)**.
GitHub Actions builds a ready-to-upload zip (Actions → "Package for cPanel").

## Quick start (without Docker)

Requires PHP 8.4 (pdo_pgsql, redis, intl, zip, gd, bcmath), Composer, Node 22,
PostgreSQL 16 and Redis 7.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
composer dev   # server + horizon + logs + vite
```

## Tests and code style

```bash
composer test        # Pest
composer lint        # Pint (fix)
```

## Feature modules

Optional features are toggled in `.env` (see `config/modules.php`):

```env
MODULE_WARMUP_ENABLED=false
```

## License

MIT
