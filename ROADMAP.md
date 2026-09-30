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

### ⬜ Phase 1: Auth, workspaces and roles
- Register, login, email verification, password reset, 2FA
- Workspaces via Filament tenancy, workspace switcher
- Roles: Owner, Admin, Member, Client (view-only)
- Team invitations by email
- Workspace settings: name, time zone, physical address (for email footers)

**Done when:** a user can create a workspace, invite a teammate, and switch workspaces with data isolated.

### ⬜ Phase 2: Email accounts and domains
- Connect a mailbox via SMTP/IMAP, with a connection test
- Encrypted credential storage
- Per-inbox: daily limit, min/max delay, send window, sender name, signature
- Status: active, paused, error
- Domain checker: SPF, DKIM, DMARC, MX, with copy-paste DNS fixes
- Custom tracking domain per inbox (CNAME check)
- `warmup_*` columns and a locked settings tab

**Done when:** a real mailbox can be added, send a test email, and show DNS health.

### ⬜ Phase 3: Leads and lists
- CSV/XLSX import: column mapping, dedupe, validation (queued, `imports` queue)
- Custom fields (JSON) usable as `{{variables}}`
- Lists, tags, segments, filters
- Lead detail page with activity timeline
- Global suppression list (email and domain), checked before every send
- CSV export

**Done when:** a 50k-row import runs in the background and leads can be filtered and tagged.

### ⬜ Phase 4: Campaign and sequence builder
- Campaigns: draft, active, paused, completed
- Multi-step sequences with per-step delays
- Editor with `{{variables}}`, fallbacks (`{{first_name|there}}`), spintax (`{Hi|Hello}`)
- Live preview with a real lead
- Assign lead lists/segments and a mailbox rotation pool
- Schedule: time zone, days, send window, daily cap
- Options: stop on reply, open/click tracking, plain-text mode
- Templates and cloning

**Done when:** a full campaign can be configured and previewed per lead.

### ⬜ Phase 5: Sending engine
Split into 5a (queue + throttling) and 5b (tracking + bounces) if needed.
- Scheduler dispatches due sends every minute (`sending` queue)
- Per-mailbox throttling with Redis rate limiters, random delays
- Inbox rotation, personalization rendering
- Open pixel, click redirect, one-click unsubscribe + `List-Unsubscribe` headers
- Suppression/unsubscribe check right before send
- Bounce parsing (hard/soft), auto-suppress hard bounces
- `email_events` table partitioned by month
- Retries and failures visible in Horizon

**Done when:** a campaign sends real email at the configured pace and events are recorded.

### ⬜ Phase 6: AI personalization
- Per-workspace AI provider: BYOK (Claude or OpenAI) or platform credits
- Prompt templates: first line, full email, subject line
- Tone, language, length controls
- Bulk generation on the `ai` queue with progress
- Review/approve queue: edit, regenerate, approve, reject
- AI output stored per lead per step, used by the sender
- Token usage tracking per workspace

**Done when:** 500 leads can be personalized, approved, and sent.

### ⬜ Phase 7: Unibox and reply detection
- IMAP polling per mailbox (`imap` queue)
- Match replies via `In-Reply-To` / `References`
- Auto-stop sequence on reply
- Unified inbox with filters
- Lead labels: Interested, Meeting Booked, Not Interested, Closed
- Reply in-thread from the original mailbox
- Out-of-office detection (rules)

**Done when:** replies from every inbox show in one place and can be answered in-thread.

### ⬜ Phase 8: Analytics dashboard
- KPIs: sent, open %, click %, reply %, bounce %, unsubscribe %
- Per campaign / step / mailbox breakdowns, trend charts
- Inbox health score (0–100)
- Date filters, CSV export

**Done when:** dashboard numbers match event data exactly.

**🎯 End of Stage 1: usable MVP.**

---

## Stage 2: Launch-ready SaaS

### ⬜ Phase 9: Compliance and deliverability safety
Complaint/bounce monitoring, auto-pause, spam-word checker, DNSBL monitoring,
GDPR export/delete, enforced unsubscribe + address footer.

### ⬜ Phase 10: Billing and super admin
Plans mapped to modules, Stripe/Paddle + bKash/SSLCommerz adapter, trials,
invoices, super admin (users, workspaces, impersonation, abuse flags, system health).

### ⬜ Phase 11: Pro mailbox and lead features
Google + Microsoft 365 OAuth mailboxes, bulk mailbox import, email verification
(`email_verification` module), A/B/n testing with auto-winner.

### ⬜ Phase 12: AI reply intelligence
Reply classification, auto-labels, AI reply drafts, website enrichment, campaign copilot.

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
