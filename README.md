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

## Campaigns

Outreach → **Campaigns**: write a multi-step sequence (follow-ups can reply in
the same thread), personalise with `{{first_name|there}}` and spintax like
`{Hi|Hello}`, pick the audience (lists and/or segments, suppressed leads are
always excluded), the mailboxes to rotate across, the schedule (time zone,
days, window, daily cap) and options (stop on reply, open/click tracking,
plain text). **Preview** shows the exact email any lead will get. **Launch**
checks everything first (sequence, active mailbox, mailing address, audience)
and enrolls the leads. Campaigns can be paused, resumed, stopped, duplicated
or saved as templates.

## Sending

Each campaign has a schedule (days and hours in a time zone), and each
mailbox has its own window (open all day by default). Emails go out only
when both are open. If an active campaign isn't sending, its page says why
(⚠ at the top, and **Sending status** lists every check).

Every minute `campaigns:send` (scheduled) picks one due lead per available
mailbox and queues the email on the `sending` queue. It honours each mailbox's
daily limit, random gap and send window, the campaign's daily cap, days, hours
and time zone, keeps each lead on the same mailbox, and never emails anyone on
the suppression list. Follow-ups reply in the same thread.

Every email has a `Message-ID`, an unsubscribe link and RFC 8058 one-click
`List-Unsubscribe` headers. Opens (pixel) and clicks (signed redirects) are
tracked when enabled. A rejected address is a hard bounce and is suppressed;
a refused login flags the mailbox; other errors retry 15 minutes later.
Campaign pages show sent/opened/clicked/bounced/unsubscribed counts and where
each lead is in the sequence.

## AI personalization

Settings → **AI settings** (owners and admins): use the included Claude
credits (`ANTHROPIC_API_KEY` on the server, a monthly token allowance per
workspace) or the workspace's own Anthropic or OpenAI key, stored encrypted.
Claude defaults to Opus 5.5 at low effort with server-side refusal fallback;
Sonnet 5.5 and Haiku 4.5 are cheaper options. The page shows this month's
token usage and estimated cost.

Outreach → **AI prompts** holds reusable instructions (what you sell, to whom)
with tone, language and length. On a campaign, **AI personalize** writes a
first line, subject or whole email for every lead in the audience in the
background (`ai` queue). Outreach → **AI review** lists the results: approve,
edit, reject or regenerate them, one by one or in bulk. Use the content in the
sequence as `{{ai_first_line}}`, `{{ai_subject}}` or `{{ai_email}}`. Only
approved content is ever sent: without a fallback (`{{ai_first_line|Hi}}`) a
campaign can't launch, and a lead's email waits, until its content is approved.

## Agencies and white label

Settings → **Clients** (owners and admins, `white_label` module) adds a client
workspace: separate leads, mailboxes, campaigns and Unibox, billed through
the agency's plan and sharing its limits. Invite the client with the
read-only Client role, or share the client's **Report** link: a branded
results page that needs no login (replace the link any time). Report emails
go out weekly (Mondays) or monthly (1st) to the addresses you set.

Workspace settings → **Branding** sets the brand name, logo and color shown in
the app, reports and invitation emails (client workspaces use the agency's).
For a custom domain, add a CNAME (e.g. `app.youragency.com` →
`CUSTOM_DOMAIN_TARGET`, by default the `APP_URL` host), add the domain to the
server with SSL, then click **Verify domain**: the sign-in page there shows the
agency's brand.

## Inbox warmup

Infrastructure → **Warmup** (or a mailbox's Warmup tab) switches warmup on
per mailbox. Warmup mailboxes email each other short, natural conversations:
2 a day at first, 2 more every day, up to the mailbox's target. The
recipient's inbox is checked over IMAP: warmup emails are rescued from spam,
marked read, filed in a "Warmup" folder and answered at the mailbox's reply
rate. The page shows each mailbox's inbox rate (share of delivered warmup
emails that landed in the inbox) over the last 7 days. The pool spans all
workspaces by default; set `WARMUP_POOL=workspace` to keep each customer's
mailboxes to themselves (then a workspace needs mailboxes on at least two
domains).

## Unibox

Outreach → **Unibox** collects replies from every connected mailbox. Every
5 minutes `inbox:sync` checks each mailbox over IMAP (read-only: nothing is
marked read or moved) and imports only replies to your campaign emails,
matched by their `In-Reply-To`/`References` headers or by the sender being a
lead that mailbox emailed. Your other mail is never stored.

A real reply stops the lead's sequence (when the campaign's "stop on reply"
is on) and marks the lead Replied. Out-of-office replies are detected by
their headers and subject, shown with a badge (hidden by default) and don't
stop anything. Bounce emails suppress hard-bounced addresses. Open a
conversation to read it, label the lead (Interested, Meeting booked, Not
interested, Closed) and reply: the reply goes out from the original mailbox
in the same thread.

To email one lead outside any campaign, use **Send email** on the lead (its
page, or the row menu in Leads). Pick the mailbox, write a subject and
message (`{{variables}}` and spintax work), and it goes out within a minute,
ignoring campaign schedules and limits. It starts a Unibox conversation
("One-off email"), so the lead's reply lands there. Suppressed leads and
paused or failing mailboxes are refused.

## Analytics

Insights → **Analytics** shows sent, open, click, reply, bounce and
unsubscribe rates for any period (in your workspace's time zone), a daily
activity chart, and breakdowns per campaign, per email of a sequence and per
mailbox. Each mailbox gets an inbox health score (0–100) with the reasons
it lost points. Rates are a share of the emails sent in the period, so they
match the event log exactly. **Export CSV** downloads any breakdown.

## Deliverability safety

- **Auto-pause.** When a mailbox's or campaign's bounce rate goes over 5%, or
  its spam-complaint rate over 0.3% (last 7 days, after at least 20 emails),
  it is paused automatically. The reason shows on the mailbox, the campaign
  and in "Sending status", and owners/admins get a notification. Resuming
  starts the count again. Limits: `AUTO_PAUSE_BOUNCE_RATE`,
  `AUTO_PAUSE_COMPLAINT_RATE`, `AUTO_PAUSE_ENABLED`.
- **Spam complaints.** Feedback-loop reports (ARF) that arrive in a connected
  mailbox suppress the lead and count towards the complaint rate.
- **Spam check.** Every email in the sequence editor shows a 0–100 score;
  "Spam check" on an email lists what to change.
- **Blacklists.** Domains → each domain is checked daily against Spamhaus,
  SURBL, URIBL, and its mail servers' IPs against Spamhaus ZEN, SpamCop,
  Barracuda and PSBL. "Check blacklists" runs it now. Spamhaus refuses
  queries from big public DNS resolvers: those show as "couldn't check".
- **GDPR.** On a lead: Privacy → "Download personal data" (JSON) or "Erase
  personal data" (owners/admins), which deletes everything and keeps only
  the address on the suppression list.

## API and integrations

- **REST API** (`/api/v1`, see [docs/API.md](docs/API.md)): create keys in
  Settings → API keys.
- **Settings → Integrations**: webhooks (Zapier, Make, n8n; signed), Slack,
  Telegram, HubSpot, Pipedrive and Google Sheets, triggered by email sent /
  opened / clicked / bounced, replies, interested replies and unsubscribes.

## AI reply intelligence

With AI set up (AI settings), every new reply is sorted (interested, wants a
meeting, question, not now, not interested, wrong person, unsubscribe
request), summarized in one line, and the lead is labelled automatically
(labels you set by hand are kept). "Remove me" replies unsubscribe the lead.
Replies worth answering get an AI draft: **Reply** opens with it filled in.
Add "About your company" and a booking link in AI settings for better drafts.

- Leads → select → **Enrich from website (AI)** adds `{{company_summary}}`,
  `{{industry}}` and `{{personalization_hook}}` from each lead's website.
- Campaign → **Write with AI** writes a whole sequence from a short brief
  (draft campaigns).

## Mailboxes: OAuth and bulk import

- **Connect Google / Microsoft 365** (Email accounts) signs in with OAuth: no
  app passwords. Create an OAuth app and set `GOOGLE_MAIL_CLIENT_ID/SECRET`
  and/or `MICROSOFT_MAIL_CLIENT_ID/SECRET` (redirect URI:
  `{APP_URL}/mailboxes/oauth/google/callback` or `.../microsoft/callback`).
  Google needs the `https://mail.google.com/` scope; Microsoft needs
  `SMTP.Send` and `IMAP.AccessAsUser.All` (Office 365 Exchange Online).
- **Add mailbox → Bulk import (CSV)**: columns `email, from_name, smtp_host,
  smtp_port, smtp_encryption, smtp_username, smtp_password, imap_host,
  imap_port, imap_encryption, imap_username, imap_password, daily_limit` (or
  `provider` = google / microsoft / zoho instead of the servers).

## Email verification

Leads → **Verify emails** checks unchecked leads (or select some and use the
bulk action): syntax, mail server, disposable and role addresses. For
mailbox-level checks set `EMAIL_VERIFICATION_PROVIDER` (millionverifier or
zerobounce) and `EMAIL_VERIFICATION_API_KEY`. Invalid addresses are never
emailed; a campaign can also send only to verified leads (Options tab).

## A/B testing

In the sequence editor, "Add a version to test" under any email (up to
versions B–E). Each lead gets one version; **A/B results** on the campaign
compares them. With "Pick the winner automatically" (Options tab), the best
reply / open / click rate wins once every version has the set number of
sends, and every later lead gets the winner.

## Billing (SaaS mode)

Off by default: a self-hosted or custom-built install has no plans or limits.
To sell workspaces on plans:

1. Set `BILLING_ENABLED=true` and configure at least one payment method in
   `.env`: Stripe (`STRIPE_*`), SSLCommerz (`SSLCOMMERZ_*`), bKash
   (`BKASH_*`) or bank transfer (`BILLING_MANUAL_*`, on by default).
2. `php artisan billing:install-plans` creates Starter / Growth / Agency;
   edit prices, limits and included modules in **/admin → Plans**. The
   "trial plan" is what new workspaces start a free trial on.
3. Customers see **Settings → Billing**: plan, usage, plans, invoices, and
   pay with the methods you enabled. bKash and SSLCommerz charge in BDT
   (`BILLING_USD_TO_BDT`). Bank transfers are confirmed in **/admin →
   Invoices**.

Webhooks: Stripe → `https://your-app/billing/webhook/stripe`
(`checkout.session.completed`), SSLCommerz IPN →
`https://your-app/billing/webhook/sslcommerz`. Payments are always
re-verified with the gateway before an invoice is marked paid.

## Super admin

`/admin` (create the account with `php artisan app:create-super-admin`):
plans, workspaces (plan, usage, abuse risk, suspend), users (log in as,
suspend), invoices, and **System health** (cron, queue, cache, disk,
mailboxes, blacklists, billing).

## Leads

Outreach → **Leads** imports CSV or Excel (.xlsx) files in the background (100-row chunks, so it
also works on shared hosting): map columns, add everything to a list, tag it,
and keep unmapped columns as custom fields usable as `{{variables}}`.
Duplicates are merged by email. Leads can be filtered by status, list, tag,
segment or suppression, changed in bulk, exported to CSV/XLSX, and each one
has an activity timeline.

**Lists** group leads, **Segments** are saved rule-based filters (e.g. "list is
SaaS founders AND tag is hot"), and the **Suppression list** holds emails or
whole domains that are never emailed. Unsubscribes and spam complaints can't
be removed from it.

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
