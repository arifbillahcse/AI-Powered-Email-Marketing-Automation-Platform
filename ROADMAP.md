# Roadmap

An Instantly.ai-style cold outreach platform with AI personalization, built with
Laravel 12 + Filament 5. Build **one phase per session**, in order. Every phase
ends with passing tests, clean Pint, and a working feature.

Status: ✅ done · 🚧 in progress · ⬜ not started

---

## Stage 1: Foundation (MVP)

### ✅ Phase 0: Project setup
- Laravel 12, PHP 8.4, PostgreSQL, Redis, Horizon
- Filament panels: `/app` (customer, indigo) and `/admin` (super admin, slate)
- Theme per `docs/STYLE_GUIDE.md`: Inter, dark mode, nav groups, AI accent classes
- Docker stack (nginx, php-fpm, horizon, scheduler, postgres, redis, mailpit)
- Pest, Pint, GitHub Actions CI
- Module flags (`config/modules.php`); Warmup registered, shown as locked
- `app:create-super-admin` command

**Done when:** `docker compose up` works, both panels load, CI is green.

### ✅ Phase 1: Auth, workspaces and roles
- Register, login, email verification, password reset, 2FA
- Workspaces via Filament tenancy, workspace switcher
- Roles: Owner, Admin, Member, Client (view-only)
- Team invitations by email
- Workspace settings: name, time zone, physical address (for email footers)

**Done when:** a user can create a workspace, invite a teammate, and switch workspaces with data isolated.

### ✅ Phase 2: Email accounts and domains
- Connect a mailbox via SMTP/IMAP, with a connection test
- Encrypted credential storage
- Per-inbox: daily limit, min/max delay, send window, sender name, signature
- Status: active, paused, error
- Domain checker: SPF, DKIM, DMARC, MX, with copy-paste DNS fixes
- Custom tracking domain per inbox (CNAME check)
- `warmup_*` columns and a locked settings tab

**Done when:** a real mailbox can be added, send a test email, and show DNS health.

### ✅ Phase 3: Leads and lists
- CSV and Excel (.xlsx, first sheet) import: column mapping, dedupe, validation (queued, `imports` queue)
- Custom fields (JSON) usable as `{{variables}}`
- Lists, tags, segments, filters
- Lead detail page with activity timeline
- Global suppression list (email and domain), checked before every send
- CSV/XLSX export

**Done when:** a 50k-row import runs in the background and leads can be filtered and tagged.

### ✅ Phase 4: Campaign and sequence builder
- Campaigns: draft, active, paused, completed
- Multi-step sequences with per-step delays
- Editor with `{{variables}}`, fallbacks (`{{first_name|there}}`), spintax (`{Hi|Hello}`)
- Live preview with a real lead
- Assign lead lists/segments and a mailbox rotation pool
- Schedule: time zone, days, send window, daily cap
- Options: stop on reply, open/click tracking, plain-text mode
- Templates and cloning

**Done when:** a full campaign can be configured and previewed per lead.

### ✅ Phase 5: Sending engine
Split into 5a (queue + throttling) and 5b (tracking + bounces) if needed.
- Scheduler dispatches due sends every minute (`sending` queue)
- Per-mailbox throttling with Redis rate limiters, random delays
- Inbox rotation, personalization rendering
- Open pixel, click redirect, one-click unsubscribe + `List-Unsubscribe` headers
- Suppression/unsubscribe check right before send
- Bounce handling: SMTP-time rejections are classified (hard bounce → suppressed,
  login failure → mailbox flagged, else retry). The DSN parser for bounce *emails*
  is ready; reading them from the inbox lands with IMAP in Phase 7
- `email_events` table (indexed; not partitioned, to stay portable across
  PostgreSQL and MySQL; archiving comes in Phase 16)
- Retries and failures visible in Horizon
- "Sending status" on every campaign: plain-language reasons it isn't sending
  right now (schedules, limits, mailbox gaps, paused mailboxes, stuck queue);
  `campaigns:send` prints the same reasons

**Done when:** a campaign sends real email at the configured pace and events are recorded.

### ✅ Phase 6: AI personalization
- Per-workspace AI provider: BYOK (Claude or OpenAI) or platform credits
  (Claude via the official Anthropic PHP SDK; monthly token allowance)
- Prompt templates: first line, full email, subject line
- Tone, language, length controls
- Bulk generation on the `ai` queue with progress (one short job per lead,
  retried with backoff on rate limits; notification when the batch is done)
- Review/approve queue: edit, regenerate, approve, reject (also in bulk)
- AI output stored per lead per step, used by the sender as `{{ai_first_line}}`,
  `{{ai_subject}}` and `{{ai_email}}`. Only approved content is sent; launch is
  blocked and sends wait while required AI content isn't approved
- Token usage tracking per workspace (with estimated Claude cost)

**Done when:** 500 leads can be personalized, approved, and sent.

### ✅ Phase 7: Unibox and reply detection
- IMAP polling per mailbox every 5 minutes (`imap` queue), with a small
  built-in IMAP client (PHP's IMAP extension is no longer bundled). Read-only:
  mail is never marked read or moved. Resumes from the last UID; a new
  mailbox starts from its first campaign email, not its whole history
- Match replies via `In-Reply-To` / `References`, falling back to the sender
  being a lead the mailbox emailed. Unrelated mail is never stored
- Auto-stop sequence on reply (per campaign "stop on reply")
- Bounce emails (DSNs) found in the inbox suppress hard-bounced leads
- Unified inbox with filters (unread, label, campaign, mailbox, out-of-office)
- Lead labels: Interested, Meeting Booked, Not Interested, Closed
- Reply in-thread from the original mailbox (queued; suppression-checked)
- One-off email to a single lead (Send email on the lead), outside any
  campaign; it starts a Unibox conversation so the reply is matched too
- Out-of-office detection (rules: Auto-Submitted/X-Autoreply/Precedence
  headers and multilingual subject patterns); shown, but sequences continue

**Done when:** replies from every inbox show in one place and can be answered in-thread.

### ✅ Phase 8: Analytics dashboard
- KPIs: sent, open %, click %, reply %, bounce %, unsubscribe % (Insights →
  Analytics, and the last 30 days on the dashboard). Every metric counts the
  emails *sent* in the period and what happened to them, straight from
  `email_messages`, whose timestamps are set by the matching events
- Per campaign / step / mailbox breakdowns, trend charts
- Inbox health score (0–100) per mailbox with the reasons for lost points
  (bounces, unsubscribes, no replies, DNS, connection, daily limit)
- Date filters (in the workspace's time zone), campaign and mailbox filters,
  CSV export (formula-injection safe)

**Done when:** dashboard numbers match event data exactly.

**🎯 End of Stage 1: usable MVP.**

---

## Stage 2: Launch-ready SaaS

### ✅ Phase 9: Compliance and deliverability safety
- Spam complaints: feedback-loop (ARF) reports found in the inbox suppress
  the lead, stop their sequences and count towards the complaint rate
- Auto-pause: a mailbox or campaign whose bounce rate (> 5%) or complaint
  rate (> 0.3%) over the last 7 days crosses the limit (after 20+ emails) is
  paused with the reason shown, and owners/admins are notified. Resuming
  restarts the count. Limits in `config/outreach.php` (`safety`)
- Inbox health score includes complaints, auto-pauses and blacklist listings
- Spam-word checker: a live score (0–100) under every email in the sequence
  editor, plus a "Spam check" button listing every issue (spam phrases, caps,
  exclamation marks, links, shorteners, images, length, prices)
- DNSBL monitoring: sending domains (Spamhaus DBL, SURBL, URIBL) and their
  SMTP servers' IPs (Spamhaus ZEN, SpamCop, Barracuda, PSBL) checked daily
  and on demand; new listings alert owners/admins; refused queries never
  count as listed
- GDPR: download everything stored about a lead (JSON), and erase a lead
  (owners/admins), keeping only the address on the suppression list
- Enforced footer: unsubscribe link + List-Unsubscribe headers and the postal
  address in every campaign email (Phase 5); the address can't be removed
  while campaigns run, and a campaign pauses rather than send without it

**Done when:** a bad list or a blacklisting stops sending by itself, and
every lead's data can be exported or erased on request.

### ✅ Phase 10: Billing and super admin
- SaaS billing behind `BILLING_ENABLED` (off = self-hosted, unlimited)
- Plans (super admin): monthly/yearly prices, trial days, limits (mailboxes,
  leads, emails per month, team members) and included modules
- Subscriptions: free trial on the default plan for new workspaces; every
  period is an invoice; renewal invoices 3 days before the period ends,
  then overdue (7-day grace), then expired (sending stops); cancel at
  period end; plan changes start when their invoice is paid
- Payment gateways behind one adapter: Stripe Checkout (signed webhooks),
  SSLCommerz (validated with the Order Validation API, IPN), bKash Tokenized
  Checkout (executed server-side), and manual bank transfer (confirmed by a
  super admin). USD prices are converted to BDT for local gateways
- Limits enforced on mailbox creation, lead creation and imports, team
  invitations, the AI module, and sending (monthly email budget per
  workspace); printable invoices; Billing page; trial/overdue/expired banner
- Super admin: Plans, Workspaces (plan, usage, 7-day bounce/complaint
  rates, abuse-risk filter, set plan, suspend), Users (log in as, suspend),
  Invoices (confirm bank transfers, void), System health (database, cron
  heartbeat, queue backlog, failed jobs, cache, disk, debug mode, mailbox
  errors, blacklists, billing), dashboard (MRR, revenue, trials, abuse watch)
- Suspended workspaces never send (campaigns, replies, one-off emails)

**Done when:** a customer can sign up, trial, pay with a card or bKash, and
be limited by their plan; the operator can run the platform from /admin.

### ✅ Phase 11: Pro mailbox and lead features
- Google + Microsoft 365 OAuth mailboxes: "Connect Google / Microsoft 365"
  on Email accounts; SMTP and IMAP sign in with XOAUTH2; tokens encrypted and
  refreshed automatically; no app passwords
- Bulk mailbox import from CSV (up to 500 per file; provider shortcuts for
  Gmail/Microsoft/Zoho; existing addresses updated; plan limit respected;
  each mailbox tested in the background; the file is deleted after reading)
- Email verification (`email_verification` module, now on by default):
  syntax, MX (or implicit A), disposable and role addresses built in;
  MillionVerifier or ZeroBounce for mailbox-level checks. Bulk action and
  "Verify emails" for every unchecked lead (queued batch, notification with
  results); invalid addresses are never emailed; "Only email verified
  leads" campaign option
- A/B/n testing: versions B–E for any sequence email; each lead gets one
  version (stable); results per version; automatic winner by reply, open or
  click rate after N sends per version (`campaigns:ab-test`, every 10 min),
  or pick one by hand; follow-ups keep the subject of the version sent

**Done when:** mailboxes connect in one click or in bulk, bad addresses are
filtered before sending, and sequences improve themselves with A/B tests.

### ✅ Phase 12: AI reply intelligence
- Reply classification (`ai` queue, per reply): interested, wants a meeting,
  question, not now, not interested, wrong person, unsubscribe request,
  other, with a one-line summary; shown in the Unibox (badge, filter,
  conversation header)
- Auto-labels: Interested / Not interested set from the reply (never over a
  label set by hand); "please remove me" replies unsubscribe the lead
- AI reply drafts for replies worth answering, using "About your company"
  and the booking link from AI settings; Reply opens with the draft filled
  in (never sent without review); "Write AI draft" on demand
- Website enrichment (bulk action): reads the lead's website or business
  email domain (SSRF-safe: every hop through HostGuard, no redirects to
  private addresses) and adds {{company_summary}}, {{industry}},
  {{personalization_hook}}
- Campaign copilot: "Write with AI" turns a short brief into a full
  sequence (subject, same-thread follow-ups, variables with fallbacks)
- All AI input from leads, replies and websites is treated as untrusted
  data in tagged blocks; usage is recorded per purpose; plan and credit
  limits apply

**Done when:** replies sort and label themselves, answers are a click away,
and a new campaign can be written from a two-line brief.

### ⬜ Phase 13: Integrations and API
REST API (Sanctum), webhooks, Zapier/Make, Slack/Telegram, HubSpot/Pipedrive, Google Sheets.

---

## Stage 3: Competitive edge

### ⬜ Phase 14: Warmup (`warmup` module)
Warmup pool, auto-replies, spam rescue, ramp-up schedules, health reports.

### ⬜ Phase 15: Agency and white label (`white_label` module)
Custom domains and branding, client sub-workspaces, client reports, reseller plans.

### ⬜ Phase 16: Advanced and scale
Inbox placement tests, multichannel steps, lead finder, dedicated workers, read replicas, event archiving.
