# Deploying on cPanel (shared hosting)

This guide installs the app on cPanel with only **Terminal, Composer,
phpMyAdmin and File Manager**. No Docker, Redis or Node.js is needed.

How it differs from the Docker setup:

| | Docker / VPS | cPanel |
|---|---|---|
| Database | PostgreSQL | MySQL / MariaDB |
| Queues | Redis + Horizon | Database queue, worked by cron every minute |
| Cache / sessions | Redis | Database |
| CSS/JS build | `npm run build` on the server | Pre-built zip from GitHub Actions |

## Before you start: check your host

- **PHP 8.3 or newer** with `pdo_mysql`, `intl`, `zip`, `gd`, `bcmath`,
  `mbstring`, `openssl`, `fileinfo`. Set the version in **MultiPHP Manager**
  and extensions in **Select PHP Version → Extensions**.
- **Outbound SMTP/IMAP allowed.** Many shared hosts block outgoing connections
  on ports 25/465/587/993. After installing, connect a mailbox and click
  **Test connection**: if it times out, ask your host to open those ports.
- **Cold email is allowed** by your host's terms of service. Many shared
  hosts forbid bulk/cold email even when it's sent through Gmail/Outlook
  mailboxes. When you start selling this as a SaaS, move to a VPS.
- **`proc_open` and `stream_socket_client` enabled** (not in `disable_functions`).

## 1. Get the release zip

1. On GitHub open **Actions → Package for cPanel → Run workflow** (it also
   runs automatically on every push to `main`).
2. When it finishes, download **outreach-cpanel-…** from the run's
   **Artifacts** section. It contains a zip with `vendor/` and the compiled
   CSS/JS already included.

> Alternative with Git: `git clone` the repo in Terminal and run
> `composer install --no-dev --optimize-autoloader`. You still need the
> compiled `public/build` folder from the release zip, because cPanel has no Node.js.

## 2. Upload the files

Keep the app **outside** `public_html`, so `.env` and the code are never
reachable from the web.

1. File Manager → home directory (`/home/USERNAME`) → create folder `outreach`.
2. Upload `outreach-cpanel.zip` into it and **Extract**.

You now have `/home/USERNAME/outreach/app`, `/home/USERNAME/outreach/public`, and so on.

## 3. Point the domain at `public/`

**Subdomain or addon domain (recommended, e.g. `app.yourdomain.com`):**
cPanel → **Domains** → create or manage the domain → set **Document Root** to
`outreach/public`.

**Main domain (`public_html`):** in Terminal:

```bash
cd ~
mv public_html public_html_backup
ln -s ~/outreach/public ~/public_html
```

## 4. Create the database

cPanel → **MySQL Database Wizard**:
1. Database: `USERNAME_outreach`
2. User: `USERNAME_outreach` with a strong password
3. Privileges: **ALL PRIVILEGES**

## 5. Configure `.env`

```bash
cd ~/outreach
cp .env.example .env
nano .env        # or edit it in File Manager
```

Set at least:

```env
APP_NAME=OutreachAI
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=USERNAME_outreach
DB_USERNAME=USERNAME_outreach
DB_PASSWORD=your-db-password

# No Redis on shared hosting
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
QUEUE_RUN_FROM_SCHEDULER=true

# App emails (invitations, password resets): use a cPanel email account
MAIL_MAILER=smtp
MAIL_HOST=mail.yourdomain.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=no-reply@yourdomain.com
MAIL_PASSWORD=email-account-password
MAIL_FROM_ADDRESS=no-reply@yourdomain.com

# Security: never allow private hosts in production
MAILBOX_ALLOW_PRIVATE_HOSTS=false

# Tracking domain CNAME target (Phase 5)
TRACKING_CNAME_TARGET=track.yourdomain.com
```

## 6. Install

```bash
cd ~/outreach
php artisan key:generate --force
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan app:create-super-admin
chmod -R 775 storage bootstrap/cache
```

> If `php` is an old version in Terminal, use the full path, e.g.
> `/opt/cpanel/ea-php83/root/usr/bin/php artisan ...` (check MultiPHP Manager).

## 7. Add the cron job (required)

cPanel → **Cron Jobs** → Common settings: **Once Per Minute** (`* * * * *`):

```bash
cd /home/USERNAME/outreach && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Use the same PHP path as step 6. This one cron runs everything: queued
emails, invitations, test emails, domain checks and, from Phase 5, campaign
sending. Without it, nothing in the background happens.

## 8. Check it works

1. Open `https://app.yourdomain.com/app/register` and create an account.
2. Team → invite yourself at another address. The email should arrive
   within a minute (sent by the cron).
3. Email accounts → connect a mailbox → **Test connection**.
4. Domains → check your sending domain's SPF/DKIM/DMARC.

## Updating

1. Download the newest release zip (step 1).
2. Upload it and extract **over** `~/outreach`. Your `.env` and `storage/` are kept.
3. Run:

```bash
cd ~/outreach
php artisan down
php artisan migrate --force
php artisan optimize
php artisan up
```

## Troubleshooting

### Use the full PHP 8.4 path for every command

cPanel's `php` command picks the PHP version from the folder of the *script
being run*. `artisan` (in the project folder) may get 8.4 while
`~/composer.phar` (in your home folder) gets the old default, so Composer
fails with "requires php ^8.3 but your php version (8.1) does not satisfy".
Always call the binary directly:

```bash
PHP84=/opt/cpanel/ea-php84/root/usr/bin/php   # or /opt/alt/php84/usr/bin/php
$PHP84 -d memory_limit=-1 ~/composer.phar install --no-dev --optimize-autoloader
$PHP84 artisan migrate --force
```

No `composer` command? Use the `composer.phar` in your home folder as above, or
download it: `curl -sS https://getcomposer.org/installer | $PHP84`.

| Problem | Fix |
|---|---|
| `php artisan ...` prints nothing at all | `vendor/` is missing (PHP hides the fatal error). Run the Composer install above. |
| "The GET method is not supported for route /" and the URL contains `/public` | The document root still points at the project folder. Set it to `.../public` (step 3). This also stops `.env` being downloadable. |
| `Class "Redis" not found` | `.env` still uses Redis. Set `SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` to `database`, then `$PHP84 artisan optimize:clear && $PHP84 artisan optimize`. |
| `.env` changes have no effect | Config is cached. Run `$PHP84 artisan optimize:clear` then `$PHP84 artisan optimize` after every `.env` edit. Check with `$PHP84 artisan about --only=environment,drivers`. |
| 500 error | Check `storage/logs/laravel.log`. Usually permissions (`chmod -R 775 storage bootstrap/cache`) or a missing `APP_KEY`. |
| Page has no styling | Run `$PHP84 artisan filament:assets`. The custom theme also needs `public/build` (release zip or cPanel's Node.js App); without it Filament's default styling is used. |
| Emails/invitations never arrive | The cron job isn't running. Check the PHP path, and look at **Cron Jobs → Cron email** output. |
| "Test connection" times out | Your host blocks outbound SMTP/IMAP ports. Ask support to open 465/587/993, or move to a VPS. |
| `Specified key was too long` during migrate | Very old MySQL. Ask your host for MySQL 5.7+/MariaDB 10.3+. |

## Large CSV imports

Imports run in the background via the cron job, 100 rows per job. A 50,000-row
file takes roughly 10–20 minutes on shared hosting; you get a notification
(bell icon) when it's done. Uploads are limited by PHP's `upload_max_filesize`
and `post_max_size` (set them to at least 20M in **Select PHP Version →
Options**) and by Livewire's 12 MB temporary upload limit. Split bigger files.

## When to move to a VPS

Shared hosting is fine for testing and for your own client hunting. Move to
a VPS (Contabo, DigitalOcean, Hetzner...) with Redis + Horizon when you:

- send more than a few hundred emails a day,
- connect more than ~10 mailboxes (IMAP reply checks get slow under cron), or
- start selling the platform to customers.

On a VPS, use the Docker setup from the README or a panel like CloudPanel
with Redis and a Supervisor process for `php artisan horizon`.
