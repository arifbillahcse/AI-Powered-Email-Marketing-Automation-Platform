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
| http://localhost:8000/app | Customer app (seeded: `demo@example.com` / `password`) |
| http://localhost:8000/admin | Super admin (seeded: `admin@example.com` / `password`) |
| http://localhost:8000/horizon | Queue dashboard (super admins) |
| http://localhost:8025 | Mailpit (catches all local email) |

Create a real super admin with `php artisan app:create-super-admin`.

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
