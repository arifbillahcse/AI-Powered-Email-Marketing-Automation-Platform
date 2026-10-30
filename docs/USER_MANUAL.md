# Operating Manual

The complete guide to using and running the AI cold email outreach platform:
for the people who send campaigns, for agencies running it for clients, and
for the owner who hosts and sells it.

> **How this manual is organised**
>
> - **Part 1, Getting started:** concepts, signing up, the first campaign.
> - **Part 2, Daily use:** every screen of the customer app, menu by menu.
> - **Part 3, Agencies:** client workspaces, white label, client reports.
> - **Part 4, Platform owner:** the super admin panel, plans, billing, payments.
> - **Part 5, Server operations:** installation, cron, queues, updates, backups.
> - **Part 6, Troubleshooting:** symptoms, causes and fixes.
> - **Appendices:** settings reference, scheduled jobs, roles, API, glossary.

---

## Contents

- [Part 1: Getting started](#part-1-getting-started)
  - [1.1 Key concepts](#11-key-concepts)
  - [1.2 Creating an account and a workspace](#12-creating-an-account-and-a-workspace)
  - [1.3 The screen layout](#13-the-screen-layout)
  - [1.4 Your first campaign in 10 steps](#14-your-first-campaign-in-10-steps)
- [Part 2: Daily use](#part-2-daily-use)
  - [2.1 Dashboard](#21-dashboard)
  - [2.2 Email accounts (mailboxes)](#22-email-accounts-mailboxes)
  - [2.3 Domains (DNS health and blacklists)](#23-domains-dns-health-and-blacklists)
  - [2.4 Warmup](#24-warmup)
  - [2.5 Inbox placement tests](#25-inbox-placement-tests)
  - [2.6 Leads](#26-leads)
  - [2.7 Lists, segments and tags](#27-lists-segments-and-tags)
  - [2.8 Lead finder](#28-lead-finder)
  - [2.9 Suppression list](#29-suppression-list)
  - [2.10 Campaigns](#210-campaigns)
  - [2.11 Multichannel steps and Tasks](#211-multichannel-steps-and-tasks)
  - [2.12 AI personalization, AI review and AI prompts](#212-ai-personalization-ai-review-and-ai-prompts)
  - [2.13 Unibox (replies)](#213-unibox-replies)
  - [2.14 Analytics](#214-analytics)
  - [2.15 Team and roles](#215-team-and-roles)
  - [2.16 Workspace settings](#216-workspace-settings)
  - [2.17 AI settings](#217-ai-settings)
  - [2.18 Integrations and API keys](#218-integrations-and-api-keys)
  - [2.19 Billing (customer side)](#219-billing-customer-side)
  - [2.20 Privacy requests (GDPR)](#220-privacy-requests-gdpr)
- [Part 3: Agencies](#part-3-agencies)
- [Part 4: Platform owner (super admin)](#part-4-platform-owner-super-admin)
- [Part 5: Server operations](#part-5-server-operations)
- [Part 6: Troubleshooting](#part-6-troubleshooting)
- [Appendix A: Settings reference (.env)](#appendix-a-settings-reference-env)
- [Appendix B: Scheduled jobs and queues](#appendix-b-scheduled-jobs-and-queues)
- [Appendix C: Roles and permissions](#appendix-c-roles-and-permissions)
- [Appendix D: Template variables and spintax](#appendix-d-template-variables-and-spintax)
- [Appendix E: REST API and webhooks](#appendix-e-rest-api-and-webhooks)
- [Appendix F: Deliverability checklist](#appendix-f-deliverability-checklist)
- [Appendix G: Glossary](#appendix-g-glossary)

---

# Part 1: Getting started

## 1.1 Key concepts

| Term | What it means |
|---|---|
| **Workspace** | Your company's private space: its mailboxes, leads, campaigns, replies and team. One account can belong to several workspaces and switch between them. |
| **Mailbox** (email account) | An inbox you send from, for example `arif@getsoftorio.com`. Campaigns rotate between several mailboxes. |
| **Sending domain** | The domain part of your mailboxes (`getsoftorio.com`). Its DNS records (SPF, DKIM, DMARC) decide whether mail lands in the inbox. |
| **Lead** | A person you want to email. Has an email address plus fields like name, company, title, and your own custom fields. |
| **List** | A named group of leads, usually one per import or target audience. |
| **Segment** | A saved filter ("title contains CEO and country is US") that stays up to date automatically. |
| **Campaign** | A sequence of emails (and optionally LinkedIn/call steps) sent to an audience, from chosen mailboxes, on a schedule. |
| **Step** | One email (or task) in a campaign's sequence, sent a number of days after the previous one. |
| **Unibox** | One shared inbox with every reply to your campaigns, from all mailboxes. |
| **Suppression list** | Addresses and domains that must never be emailed (unsubscribed, bounced, complained, or blocked by you). |
| **Warmup** | Automatic, gradual exchange of real-looking emails between mailboxes to build a good sender reputation. |
| **Module** | An optional feature (AI, warmup, white label...) that the platform owner can switch on or sell in specific plans. |

**How sending works, in one paragraph.** When a campaign is launched, every
lead in its audience is enrolled. Every minute the system looks for mailboxes
that are allowed to send right now (inside their sending window, under their
daily limit, after their random pause between emails) and gives each one the
next lead that is due. The email is rendered for that lead, checked against
the suppression list one last time, and sent through the mailbox's own SMTP
server. Replies are found by checking each mailbox over IMAP every 5 minutes;
a reply stops that lead's sequence.

## 1.2 Creating an account and a workspace

1. Open the website and click **Start free** (or go to `/app/register`).
2. Enter your name, email and a password, then confirm your email address
   from the message you receive.
3. Create your first **workspace**: give it your company (or client) name and
   choose its **time zone**. Schedules and reports use this time zone.
4. If the platform sells plans, a **free trial** starts automatically on the
   default plan. You can see how many days are left in the banner at the top.

**Security tip:** turn on two-factor authentication. Click your avatar
(top right) → **Profile** → set up an authenticator app. Save the recovery
codes somewhere safe.

## 1.3 The screen layout

- **Left sidebar:** the main menu, in four groups:
  - **Outreach:** Unibox, Campaigns, Leads, Lead finder, Lists, AI review,
    AI prompts, Message templates, Tags, Tasks, Segments, Suppression list.
  - **Infrastructure:** Email accounts, Domains, Warmup, Inbox placement.
  - **Insights:** Analytics.
  - **Settings:** Clients (agencies), Billing, Team, AI settings,
    Integrations, API keys.
- **Workspace switcher** (top of the sidebar): switch workspaces, open
  **Workspace settings**, or create a new workspace.
- **Bell icon:** notifications. Long-running jobs (imports, verification, AI
  batches, placement tests, lead finder) tell you here when they finish.
- **Avatar menu:** profile, two-factor authentication, **Help center**, sign out.
- **Banner at the top:** trial days left, overdue payment, or why sending is
  paused.

Features marked **Soon** or showing a lock are switched off by the platform
owner or not included in your plan.

## 1.4 Your first campaign in 10 steps

The **Get started** checklist on your dashboard tracks these for you.

1. **Connect a mailbox:** Infrastructure → Email accounts → *Sign in with
   Google*, *Sign in with Microsoft*, or *New email account* for any
   SMTP/IMAP inbox. See [2.2](#22-email-accounts-mailboxes).
2. **Fix your DNS:** Infrastructure → Domains. Every check should be green.
   See [2.3](#23-domains-dns-health-and-blacklists).
3. **Add your mailing address:** workspace switcher → Workspace settings →
   *Mailing address*. Required by anti-spam law; campaigns can't launch
   without it.
4. **Start warmup:** Infrastructure → Warmup → *Settings* on each new
   mailbox → *Warm up this mailbox*. Ideally wait 2–4 weeks before cold email.
5. **Import leads:** Outreach → Leads → *Import* (CSV or Excel), or use the
   Lead finder.
6. **Verify the list:** select the leads → *Verify emails*. Invalid addresses
   are never emailed.
7. **Write the campaign:** Outreach → Campaigns → *Template library* → pick
   one → replace the `[bracketed]` parts with your own words.
8. **Choose the audience and mailboxes:** *Audience* tab (lists/segments),
   *Sending* tab (mailboxes, days, hours, daily limit).
9. **Preview and test:** *Preview* shows the exact email for any lead;
   *Send test email* sends it to you.
10. **Launch.** The system lists anything missing before it lets you launch.
    Sending then starts slowly inside your sending window.

---

# Part 2: Daily use

## 2.1 Dashboard

- **Get started checklist:** shown until every setup step is done or you
  click *Hide checklist*. Each unfinished step links to the right page.
- **Account:** who you're signed in as.
- **Last 30 days:** emails sent, open, click, reply and bounce rates for the
  whole workspace. Click through to Analytics for details.

## 2.2 Email accounts (mailboxes)

**Infrastructure → Email accounts**

### Connecting a mailbox

| Method | When to use | What you need |
|---|---|---|
| **Sign in with Google** | Google Workspace / Gmail | Just sign in. No password is stored (a secure token is). |
| **Sign in with Microsoft** | Microsoft 365 / Outlook | Sign in. Your admin may need to allow the app. |
| **New email account** | cPanel mail, Zoho, any other provider | SMTP and IMAP host, port, encryption, username and password. |
| **Bulk import** | Many mailboxes at once | A CSV with a header row and one mailbox per row; the dialog lists the columns (email, from_name, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, imap_host, ...). |

For manual setup:

- **SMTP:** usually port **587 with STARTTLS** (choose *TLS*) or **465 with
  SSL**.
- **IMAP:** usually port **993 with SSL**.
- **Username:** normally the full email address.
- **Gmail with a password:** you must use an **app password** (Google
  Account → Security → 2-Step Verification → App passwords).

After saving, the connection is tested. Use **Test connection** any time
later. A mailbox that fails shows status **Error** with the reason; fix the
settings and test again.

### The mailbox form tabs

| Tab | Settings |
|---|---|
| **Account** | Email address, sender name (what recipients see), provider. |
| **Servers** | SMTP and IMAP connection details (passwords are never shown again; leave blank to keep the current one). |
| **Sending limits** | **Daily limit** (cold emails per day from this mailbox; 30–50 is safe), **minimum/maximum delay** between two emails (a random pause in between), **sending window** (hours) and **days**. |
| **Signature** | Added to the bottom of every email from this mailbox. |
| **Tracking domain** | Your own subdomain for open/click links, e.g. `track.getsoftorio.com`. Add a CNAME to the target shown, save, then click *Verify tracking domain*. Better deliverability than the shared default. |
| **Warmup** | Turn warmup on, target emails per day, reply rate. |

### Status and pausing

- **Active:** sends and is checked for replies.
- **Paused:** you paused it, or it was **auto-paused** because its bounce or
  spam-complaint rate got too high (the reason is shown). Fix the cause
  (usually a bad list), then **Resume**. Rates are counted again from the
  moment you resume.
- **Error:** the last connection test or send failed (wrong password,
  revoked OAuth access, server down). Edit and test again.

## 2.3 Domains (DNS health and blacklists)

**Infrastructure → Domains**

Every domain your mailboxes use is listed automatically. Click **Check now**
to run the checks:

| Check | Meaning | Fix |
|---|---|---|
| **MX** | The domain can receive mail (needed for replies and bounces). | Add MX records from your email provider. |
| **SPF** | Which servers may send for the domain. | One TXT record, e.g. `v=spf1 include:_spf.google.com ~all`. Only **one** SPF record per domain. |
| **DKIM** | Emails are cryptographically signed. | Generate the key in your email provider's admin panel, add the TXT record (e.g. `google._domainkey`), then turn signing on there. |
| **DMARC** | Policy for failing mail plus reports. | TXT at `_dmarc.yourdomain.com`: start with `v=DMARC1; p=none; rua=mailto:dmarc@yourdomain.com`. |
| **Blacklists** | Whether the domain or its mail servers are listed on spam blocklists (DNSBL). | Find out why (usually spam complaints or a compromised account), fix it, then request delisting from that list. |

Domains are re-checked every night (DNS at 03:00, blacklists at 04:00). If a
blacklist shows **couldn't check**, the server's DNS resolver was refused by
that list (common on shared hosting); it is not a listing.

**Tip:** send cold email from a separate domain (`getsoftorio.com` rather
than `softorio.com`) so your main domain's reputation is protected.

## 2.4 Warmup

**Infrastructure → Warmup**

Warmup builds trust with mailbox providers before (and while) you send cold
email.

**How it works**

1. Mailboxes with warmup on send each other short, natural business emails
   (no links, plain text).
2. The receiving side opens them, **moves them out of spam** if they landed
   there, marks them read, files them in a **Warmup** folder (so they don't
   clutter the inbox), and replies to some of them.
3. Volume starts at **2 emails a day** and grows by **2 a day** until the
   mailbox's target.

**The Warmup page shows, per mailbox:**

- **Warmup:** Off, *Day N*, or *Needs attention* (with the error).
- **Today:** warmup emails started today / today's quota.
- **Sent, Inbox rate, Saved from spam, Replies:** last 7 days. Inbox rate
  above 90% is healthy; below 75% means a deliverability problem.

**Settings** (per mailbox): turn on/off, target per day (20–40 is plenty), and
reply rate (how often received warmup emails are answered).

**Good practice:** warm new mailboxes for 2–4 weeks before cold email, and
keep warmup on while campaigns run. Turning warmup off and on again restarts
the ramp from day 1. Warmup needs mailboxes on at least two different domains
in the pool.

## 2.5 Inbox placement tests

**Infrastructure → Inbox placement**

A placement test shows where your email actually lands (inbox, spam or
missing) at Gmail, Outlook, Yahoo and other providers.

1. Click **New test**.
2. Choose the mailbox to send from, paste your real **subject** and **body**
   (as plain text; links count).
3. Optionally tick **Also send to my other mailboxes**.
4. Results arrive within about 30 minutes; you get a notification.

The table shows the **Inbox** rate with the counts (inbox · spam · missing),
and **Results** lists each seed by provider. Run a test before a big launch,
after changing DNS, and whenever replies suddenly drop.

## 2.6 Leads

**Outreach → Leads**

### Adding leads

- **New lead:** one at a time.
- **Import:** upload a **CSV or Excel (.xlsx)** file. Map your columns to
  lead fields (email, first name, last name, company, title, phone, website,
  LinkedIn URL, city, country, time zone); unmapped columns can become
  **custom fields** (usable as `{{field_name}}` in emails). Choose a list
  to add them to. Large files import in the background; you get a
  notification when done. Existing leads (same email) are updated, not
  duplicated.
- **Lead finder:** see [2.8](#28-lead-finder).
- **API / integrations:** see [Appendix E](#appendix-e-rest-api-and-webhooks).

### Working with leads

- **Search and filters** at the top of the table (status, list, tag,
  verification, ...).
- **Lead statuses:** New, Contacted, Replied, Interested, Meeting booked, Not
  interested, Closed, Bounced, Unsubscribed. Most change automatically;
  you can also set them.
- **Bulk actions** (select leads, then the actions menu): *Verify emails*,
  *Enrich from website (AI)*, *Add to list* (or create a new list), *Remove
  from list*, *Add tags*, *Remove tags*, *Change status*, *Add to
  suppression list*, *Delete*. Bulk actions work on huge selections in the
  background.
- **Star, follow-up and notes in the table:** click the star at the start of
  a row to mark an important lead. Filters: *Starred*, *Follow-up due* and
  *Note contains*. The number on the Leads menu item is how many follow-ups
  are due today.
- **Row buttons:** *Write email*, *Add note*, and in the ⋮ menu *Tags*, *Star
  lead*, *Follow up* and *Follow-up done*.
- **Export:** download the current selection as CSV.

### The lead page

Open a lead to see its details, lists, tags and a **timeline** of everything
that happened: created, imported, emails sent, opened, clicked, replied,
bounced, tasks done, status changes. From here you can:

- **Write email:** a personal one-off email outside any campaign. See
  *Writing to one lead* below.
- **Add note:** write down what you found on their website, who you
  contacted and what to do next. Notes are listed under the timeline (newest
  first) with who wrote them and when; the latest one also shows in the
  Status box. Everyone in the workspace reads them, you edit your own, and
  owners and admins can delete any. Notes are never sent to the lead.
  Clients (the read-only role) can read notes but not write them.
- **Organize → Tags:** set the lead's tags without opening the edit form.
  New tags are gray; give them a color under **Outreach → Tags** (gray,
  indigo, green, amber, red or blue). Renaming or deleting a tag there
  changes it on every lead that has it.
- **Organize → Star lead:** marks an important lead (the filled star in the
  table).
- **Organize → Follow up:** pick the day to come back to this lead. That
  morning (08:00 in the workspace time zone) you get a notification, the
  lead appears under the *Follow-up due* filter and in the Leads menu count,
  and it turns red once overdue. Choose **Follow-up done** when finished.
  Each note and follow-up is also logged on the timeline.
- **Custom fields:** values that start with `https://` or `http://` are
  links that open in a new tab, and email addresses open your mail app.
  Anything else (including `javascript:` text) stays plain text.
- **Export personal data / Erase personal data:** see [2.20](#220-privacy-requests-gdpr).

### Writing to one lead

When you spot a lead worth a personal message, write to them in a few
seconds:

1. Click the **envelope icon** on the lead's row in the leads table, or
   **Write email** at the top of the lead's page.
2. The composer is ready:
   - **From** is already chosen: the mailbox that last emailed this lead,
     otherwise the one you used last.
   - The message starts with `Hi {{first_name|there}},` and your mailbox
     signature is added when sending.
   - The top line tells you when the lead was last contacted and the latest
     message between you, so you don't repeat yourself.
3. Optionally pick a **Template**: its subject and message fill in; change a
   line to make it personal.
4. Press **Ctrl+Enter** (⌘+Enter on Mac) or click **Send email**.

It goes out within a minute from that mailbox (campaign schedules and limits
don't apply) and starts a Unibox conversation, so the reply lands there. The
lead's timeline shows "Email sent", and a New lead becomes Contacted.
Suppressed leads and paused or failing mailboxes are refused.

**Message templates** (Outreach → Message templates) are the short emails
you send often, e.g. "Intro: web development" or "Hosting offer". Create them
there, or tick **Save as a message template** in the composer to keep what
you just wrote. Variables (`{{first_name|there}}`, `{{company}}`,
`{{website}}`, `{{sender_first_name}}`...) are filled in for each lead. The
most used templates are listed first.

### Email verification

**Verify emails** checks syntax, the domain's mail servers, disposable and
role addresses (info@, sales@...) and, if the platform owner configured a
verification service, whether the mailbox exists. Results: *valid*,
*risky*, *invalid*, *unknown*. Invalid addresses are **never** emailed by
campaigns; a campaign can also be set to **only email verified leads**.

### AI enrichment

**Enrich from website (AI)** reads each lead's company website and fills a
short company summary and talking points you can use in AI-personalized
emails.

## 2.7 Lists, segments and tags

- **Lists** (Outreach → Lists): fixed groups. A lead can be in many lists.
- **Segments** (Outreach → Segments): saved rules, e.g. *company equals
  Acme*, *title contains founder*, *status is New*, *tag is hot*. A segment
  always includes the leads that match right now, so new imports join
  automatically.
- **Tags:** free labels on leads (e.g. `hot`, `webinar-2026`). Filter and
  build segments on them.

A campaign's audience can combine several lists and segments; a lead in
both is enrolled once.

## 2.8 Lead finder

**Outreach → Lead finder** (needs the platform's Hunter.io key)

1. Click **Find leads**.
2. Enter company **domains**, one per line (up to 25). Website URLs and
   email addresses are cleaned up automatically.
3. Optionally filter by **department** and **seniority**, and set **people
   per company**.
4. Choose an existing **list** or name a **new list**.
5. The search runs in the background; you get a notification with the number
   of new leads.

Only addresses with good confidence are imported. Existing leads are just
added to the list, suppressed addresses are skipped, and your plan's lead
limit applies. **Verify the list before sending.**

## 2.9 Suppression list

**Outreach → Suppression list**

Nobody on this list is ever emailed, in any campaign or one-off email.

- Added automatically: **unsubscribes**, **hard bounces** and **spam
  complaints**.
- Add manually: single addresses or whole **domains** (e.g. a competitor),
  or paste many at once.
- Automatic unsubscribe and complaint entries can't be removed by members
  (to stay compliant).

## 2.10 Campaigns

**Outreach → Campaigns**

### Creating a campaign

- **New campaign:** start from scratch.
- **Template library:** ready-made sequences: classic 3-email cold outreach,
  agency services, SaaS demo, recruiting, webinar/event invite,
  LinkedIn + email (multichannel), re-engage old leads. Replace the
  `[bracketed]` parts.
- **Use a template:** your own saved templates (any campaign → *Save as
  template*).
- **Duplicate:** copy an existing campaign.
- **AI copilot:** describe your offer and audience in a few lines and let AI
  write the whole sequence (you edit before launching).

### Sequence tab

Each step has:

- **Step type:** Email, or a manual step (LinkedIn: view profile /
  connection request / message, Phone call, Other task). See [2.11](#211-multichannel-steps-and-tasks).
- **Wait:** days after the previous step (ignored for the first).
- **Subject:** leave it **blank on a follow-up** to reply in the same thread
  ("Re: ..."); most replies come from follow-ups.
- **Body:** with formatting, `{{variables}}` and `{spin|tax}`
  ([Appendix D](#appendix-d-template-variables-and-spintax)).
- **Spam check:** shows spammy words, too many links, link shorteners, etc.
- **A/B test versions:** add versions B, C, D, E of any email. Each lead gets
  one version; compare them with **A/B results**.

### Audience tab

Pick **lists** and/or **segments**. Suppressed, invalid (and, if you choose,
unverified) leads are left out automatically.

### Sending tab

| Setting | Meaning |
|---|---|
| **Send from** | The mailboxes to rotate between. A lead always gets follow-ups from the mailbox that sent its first email. |
| **Time zone, Send from / Send until, Send on** | The campaign's sending window. Both the campaign's and the mailbox's windows must be open to send. |
| **Campaign daily limit** | Maximum emails per day for this campaign (mailbox limits still apply). |

### Options tab

| Option | Recommendation |
|---|---|
| **Stop the sequence when a lead replies** | Keep on. Out-of-office replies don't stop it. |
| **Track opens** | Optional. Open tracking adds a tiny image; it can slightly hurt deliverability and many apps block it. |
| **Track link clicks** | Use only with your own tracking domain. |
| **Send as plain text** | Often the best choice for cold email. Links become "text (url)". |
| **Only email verified leads** | Strongly recommended for purchased or old data. |
| **A/B testing:** pick the winner automatically, winner by (replies, opens, clicks), after this many sends per version | Defaults: by replies, after 100 sends per version. |

### Before launching

- **Preview:** the exact email (subject, body, footer) for any lead in the
  audience, each step.
- **Send test email:** sends one step, rendered for a chosen lead, to your
  own address.
- **Launch:** if anything is missing (no subject, no mailbox, empty audience,
  no mailing address, AI content not approved...), you get a list of what to
  fix.

### While running

- **Sending status:** plain-language reasons why the campaign is or isn't
  sending right now (outside the window, daily limit reached, mailboxes
  paused, no leads due yet, background worker not running...). Check it
  first when nothing seems to happen.
- **Pause / Resume.** A campaign can also be **auto-paused** for high
  bounces or complaints, or because the mailing address was removed. The
  reason is shown on the campaign.
- **Add new leads:** enroll leads that joined the audience after launch.
- **Leads tab:** each lead's progress (step, status, next send time).
- The campaign becomes **Completed** when every lead has finished.

### Compliance (automatic)

Every campaign email gets an **unsubscribe link**, the **List-Unsubscribe**
headers Gmail and Yahoo require, and your **postal address**. Each lead is
re-checked against the suppression list right before sending. These can't be
turned off.

## 2.11 Multichannel steps and Tasks

Add LinkedIn and phone steps to a sequence by changing a step's **type**:

| Type | Task created |
|---|---|
| LinkedIn: view profile | Visit the lead's LinkedIn profile. |
| LinkedIn: connection request | Send a connection request (notes = your message). |
| LinkedIn: message | Send a LinkedIn message. |
| Phone call | Call the lead (their phone number is shown). |
| Other task | Anything else you write in the title/notes. |

When a lead reaches a manual step, a task appears in **Outreach → Tasks**
(the menu shows how many are open) and that lead's sequence **waits**.

On the Tasks page:

- **LinkedIn:** opens the lead's LinkedIn profile.
- **Notes:** your instructions or script for this lead (variables filled in).
- **Done:** the sequence continues to the next step after its wait.
- **Skip:** the sequence continues without doing it.

Filter by status (To do / Done / Skipped) and step type. Manual steps need no
subject or body.

## 2.12 AI personalization, AI review and AI prompts

### Personalizing a campaign

1. In a campaign's actions, choose **AI personalize**.
2. Pick what to write (**first line**, **subject** or **whole email**), the
   prompt template, tone, language and length.
3. AI writes it for every lead in the audience, in the background.
4. Use it in the sequence as `{{ai_first_line}}`, `{{ai_subject}}` or
   `{{ai_email}}`.

### AI review

**Outreach → AI review** lists everything AI wrote. **Only approved content
is ever sent.** Approve, edit, reject or regenerate, one by one or in bulk.

- Without a fallback (e.g. `{{ai_first_line}}`), a campaign can't launch, and
  a lead's email waits, until that lead's content is approved.
- With a fallback (e.g. `{{ai_first_line|Hope you're well.}}`), unapproved
  leads get the fallback text.

### AI prompts

**Outreach → AI prompts:** your own prompt templates (instructions for what
the AI should write). Lead data is always treated as untrusted input, so a
lead can't "instruct" the AI.

## 2.13 Unibox (replies)

**Outreach → Unibox**

All replies to your campaigns and one-off emails, from every mailbox,
checked every 5 minutes. Your other mail is never stored.

- **AI category** (when reply intelligence is on): Interested, Wants a
  meeting, Question, Not now, Not interested, Wrong person, Unsubscribe
  request, Other. Filter by it to work the hottest replies first.
- **Out-of-office** replies are detected, badged and hidden by default; they
  don't stop the sequence.
- **Reply:** answers from the original mailbox in the same thread.
- **AI draft:** writes a suggested answer (using your company context and
  meeting link from AI settings); edit before sending.
- **Label:** set the lead's status (Interested, Meeting booked, Not
  interested, Closed).
- **Mark unread**, **View lead**.

A real reply automatically marks the lead **Replied** and stops its
sequence. Bounce messages are processed too: hard bounces suppress the
address.

## 2.14 Analytics

**Insights → Analytics**

- **Filters:** period (today, 7/30/90 days, this/last month, custom), campaign,
  mailbox. Periods follow the workspace time zone.
- **KPIs:** sent, opened, clicked, replied, bounced, unsubscribed, with rates.
- **Activity chart:** per day.
- **Breakdowns:** by campaign, by step (for one campaign) and by mailbox,
  including mailbox health.
- **Export CSV** of the current view.

Rates are computed on the emails sent in the period ("of the 1,000 emails
sent this week, 52 got a reply"), so they never exceed 100%.

**Healthy benchmarks for cold email:** bounce rate under 3%, spam complaints
under 0.1%, reply rate 2–10% depending on targeting.

## 2.15 Team and roles

**Settings → Team** (owners and admins)

- **Invite:** by email, as **Admin**, **Member** or **Client**. The link is
  valid for 7 days; resend or revoke from *Pending invitations*.
- **Change role / remove** members.
- **Leave** a workspace. The owner can't leave, be removed or have their
  role changed.

| Role | Can do |
|---|---|
| **Owner** | Everything, including billing. Can't be removed. |
| **Admin** | Manage campaigns, mailboxes, team members and settings. |
| **Member** | Run campaigns, manage leads, reply from the Unibox. |
| **Client** | Read-only: see campaigns, replies and reports; change nothing. |

Ownership is never given through invitations or role changes. See
[Appendix C](#appendix-c-roles-and-permissions) for the full matrix.

## 2.16 Workspace settings

Open from the **workspace switcher**:

- **General:** name, time zone.
- **Mailing address:** company name, address, city, postal code, country
  (2-letter code). Printed in every campaign footer; required while
  campaigns are active or paused.
- **Branding (white label):** see [Part 3](#part-3-agencies).

## 2.17 AI settings

**Settings → AI settings**

- **Provider:** use the platform's included AI credits, or bring your own
  key (Claude by Anthropic, or OpenAI) with your own model choice. Keys are
  stored encrypted and never shown again.
- **Reply intelligence:** classify replies, auto-label leads from the
  category, auto-draft answers; plus your **company context** (what you
  sell, to whom) and **meeting link** used in drafts.
- **Usage this month:** tokens used by purpose (personalization, replies,
  enrichment, copilot) and the remaining allowance.

## 2.18 Integrations and API keys

### Integrations (Settings → Integrations)

Send events to other tools automatically:

| Integration | What it does |
|---|---|
| **Webhook** | POSTs a signed JSON payload to any URL (Zapier, Make, n8n, your own app). |
| **Slack** | Posts a message to a channel (incoming webhook URL). |
| **Telegram** | Posts to a chat via your bot. |
| **HubSpot** | Creates/updates the contact. |
| **Pipedrive** | Creates/updates the person and adds a note. |
| **Google Sheets** | Appends one row per event (service account). |

Choose which **events** each integration receives: email sent, email
opened (first open), link clicked (first click), email bounced, lead replied,
interested reply (AI), reply sorted by AI, lead unsubscribed or complained.
Every delivery is logged and retried; an integration that keeps failing (20
times in a row) is switched off and you're notified. Secrets are encrypted
and never shown again (leave them blank to keep them).

### API keys (Settings → API keys)

Create a key with **read** or **read + write** access and an optional expiry.
The key is shown **once**; copy it then. See
[Appendix E](#appendix-e-rest-api-and-webhooks).

## 2.19 Billing (customer side)

**Settings → Billing** (owners and admins; only when the platform sells plans)

- **Your plan:** name, status (trial, active, past due, expired) and period.
- **Usage:** mailboxes, leads, emails this month, team members (and client
  workspaces for agency plans) against the plan's limits.
- **Plans:** compare and **Choose a plan / Change plan**, monthly or yearly.
- **Pay** open invoices by card (Stripe), SSLCommerz, bKash or bank transfer
  (whichever the platform enabled). bKash and SSLCommerz charge in BDT.
- **Invoices:** view and print/save as PDF.
- **Cancel subscription:** stays active until the end of the paid period;
  **Keep my subscription** undoes it.

**What happens when payment is late:** the subscription becomes *past due*
with a grace period (7 days by default), then *expired*: sending stops (the
data is kept) until the open invoice is paid.

## 2.20 Privacy requests (GDPR)

On a lead's page:

- **Export personal data:** downloads everything stored about that person
  (fields, lists, emails, replies, activity) as a file.
- **Erase personal data:** deletes the lead and their messages, and keeps
  only the address on the suppression list (marked *Erased*) so they are
  never emailed again.

Unsubscribes are honoured instantly and automatically.

---

# Part 3: Agencies

Requires the **White Label** module in the plan.

## 3.1 Client workspaces

**Settings → Clients** (owners and admins of the agency workspace)

1. **Add client:** name, time zone, and optionally the client's email to
   invite them with the **read-only Client role**.
2. You become owner of the new workspace; the agency's other owners and
   admins become its admins.
3. **Open** switches into the client workspace to connect their mailboxes,
   import their leads and run their campaigns.

The Clients table shows, per client: mailboxes, active campaigns, emails sent
in 30 days and reply rate.

**Billing:** client workspaces use the **agency's plan**. Mailboxes, leads and
emails per month are **shared** across the agency and all its clients, and
the plan's *client workspaces* limit caps how many clients you can add.
Clients never see billing. If the agency's plan lapses, client workspaces
pause sending with a "contact your agency" notice.

## 3.2 Branding (white label)

Agency workspace → **Workspace settings → Branding (white label)**:

- **Brand name**, **brand color** and **logo** (PNG, JPG or WebP, up to
  512 KB). They replace the platform's own in the app (for the agency and all
  its client workspaces), on client reports and in invitation emails.

## 3.3 Custom domain

Let clients sign in at your own address, e.g. `app.youragency.com`:

1. Add a **CNAME** record: `app.youragency.com` → the target shown in the
   form (the platform's host).
2. Ask the platform owner to add the domain to the server with SSL (cPanel:
   Domains + AutoSSL).
3. Enter it in **Custom domain**, save, then click **Verify domain**.

Once verified, the sign-in page on that domain shows your branding, and the
public marketing site is never shown there.

## 3.4 Client reports

On the Clients page, per client:

- **Report:** opens the client's **secret report link**: a branded,
  read-only page with results for 7, 30 or 90 days (KPIs and per campaign).
  Anyone with the link can view it without logging in.
- **New report link:** replaces the link (the old one stops working).
- **Report emails:** send the report automatically **weekly (Mondays)** or
  **monthly (1st of the month)** to up to 10 addresses.

---

# Part 4: Platform owner (super admin)

## 4.1 Accessing the admin panel

Create the first super admin on the server:

```bash
php artisan app:create-super-admin
```

Then sign in at **`/admin`**. The admin panel has its own menu:

- **Customers:** Workspaces, Users
- **Billing:** Plans, Invoices
- **System:** System health
- Dashboard widgets: platform stats and **abuse watch**.

## 4.2 Selling plans

Turn billing on with `BILLING_ENABLED=true` (off means everything is free
and unlimited, for self-hosted single-company installs).

```bash
php artisan billing:install-plans   # sample Starter, Growth, Agency plans
```

**Billing → Plans:** for each plan set:

- Name, description, currency, **monthly and yearly price**, **trial days**.
- **Limits** (blank = unlimited): mailboxes, leads, emails per month, team
  members, client workspaces.
- **Modules** included: AI personalization, API & integrations, warmup,
  email verification, white label, multichannel steps, inbox placement
  tests, lead finder.
- **Active** (shown on pricing page and in the app) and **Default** (used for
  new sign-up trials).

> Existing plans are not changed when new modules are added in an update.
> Edit them and tick the new modules, otherwise customers see those features
> locked.

## 4.3 Payment gateways

Enable the ones you want in `.env` (see [Appendix A](#appendix-a-settings-reference-env)):

| Gateway | Notes |
|---|---|
| **Stripe** | Cards. Set the webhook in Stripe to `https://YOUR-APP/billing/webhook/stripe`. |
| **SSLCommerz** | Bangladesh cards, mobile banking. Test with `SSLCOMMERZ_SANDBOX=true` first. |
| **bKash** | Tokenized checkout. Test with `BKASH_SANDBOX=true` first. |
| **Manual** | Bank transfer etc. with your instructions; you mark the invoice paid. |

Payments are always re-verified with the gateway; the browser redirect alone
never marks an invoice paid. Non-BDT prices are converted with the exchange
rates in `BILLING_USD_TO_BDT` etc. for bKash/SSLCommerz.

## 4.4 Managing customers

**Customers → Workspaces:** plan, billing status, mailboxes, leads,
members, 7-day bounce and complaint rates, suspension. Actions:

- **Set plan:** change plan/status/period directly (custom deals, free
  extensions) without an invoice.
- **Dedicated sending:** moves a big sender to its own `sending-priority`
  queue and workers.
- **Suspend / Unsuspend:** stops all sending immediately; the customer sees
  your reason.
- Filter **Abuse risk** to find workspaces with high bounces or complaints.

**Customers → Users:** search users, suspend a user, and **Impersonate**
(sign in as them to give support; a bar lets you stop impersonating).

**Billing → Invoices:** all invoices; mark manual payments as paid; void.

## 4.5 System health

**System → System health** checks the database, the cron/scheduler
(heartbeat), the queue backlog, failed jobs in the last 24 hours, the cache,
storage (including the public storage link), debug mode and blacklist
monitoring, and shows the software versions. Check it after every
deployment.

## 4.6 Modules (feature flags)

Each optional feature can be switched off for everyone in `.env`:

```
MODULE_AI_ENABLED=true
MODULE_API_ENABLED=true
MODULE_WARMUP_ENABLED=true
MODULE_EMAIL_VERIFICATION_ENABLED=true
MODULE_WHITE_LABEL_ENABLED=true
MODULE_MULTICHANNEL_ENABLED=true
MODULE_INBOX_PLACEMENT_ENABLED=true
MODULE_LEAD_FINDER_ENABLED=true
```

A switched-off module shows as locked ("Soon") in the app. When billing is on,
a module must also be included in the customer's plan.

## 4.7 Platform services to configure

| Feature | What to set up |
|---|---|
| **Google mailbox sign-in** | Google Cloud OAuth client (Gmail API scope); redirect URI `https://YOUR-APP/mailboxes/oauth/google/callback`; `GOOGLE_MAIL_CLIENT_ID/SECRET`. |
| **Microsoft mailbox sign-in** | Azure app registration; redirect URI `https://YOUR-APP/mailboxes/oauth/microsoft/callback`; `MICROSOFT_MAIL_CLIENT_ID/SECRET/TENANT`. |
| **Included AI credits** | `ANTHROPIC_API_KEY`, `AI_PLATFORM_MODEL`, `AI_PLATFORM_MONTHLY_TOKENS` per workspace. |
| **Email verification service** | `EMAIL_VERIFICATION_PROVIDER` (millionverifier or zerobounce) + `EMAIL_VERIFICATION_API_KEY`. Without it, only built-in checks run. |
| **Lead finder** | `HUNTER_API_KEY` (Hunter.io). |
| **Inbox placement seeds** | Create a workspace (e.g. "Seed accounts"), connect a few Gmail/Outlook/Yahoo mailboxes, set `PLACEMENT_SEED_WORKSPACE` to its slug. |
| **Tracking domains** | `TRACKING_CNAME_TARGET` (default `track.<your host>`); point that host to the server. |
| **System email** | `MAIL_*` settings for invitations, password resets, client reports. |
| **Public website** | `MARKETING_COMPANY`, `MARKETING_CONTACT_EMAIL`, tagline; edit help articles in `resources/help/*.md`. Have the terms and privacy pages reviewed for your business. |

## 4.8 Demo account

For sales demos (e.g. Fiverr buyers):

```bash
php artisan app:demo --email=demo@yourdomain.com --password='choose-a-password'
php artisan app:demo --email=demo@yourdomain.com --fresh   # reset the demo data
```

It creates a "Demo Agency" workspace with 60 leads, 2 lists, a draft
campaign and a finished campaign with 30 days of results. It never sends
real email.

---

# Part 5: Server operations

## 5.1 Hosting options

| | cPanel shared hosting | VPS / Docker |
|---|---|---|
| Database | MySQL / MariaDB | PostgreSQL 16 (recommended) or MySQL |
| Queues | Database, worked by cron every minute | Redis + Horizon (continuous workers) |
| Cache / sessions | Database | Redis |
| Scale | Small to medium | Large; dedicated workers, read replicas |

Full cPanel walkthrough: **`docs/DEPLOY_CPANEL.md`**.

## 5.2 Installation summary (cPanel)

1. Upload/clone the code; point the domain's document root to `public/`.
2. Create a MySQL database and user.
3. Copy `.env.example` to `.env`; set `APP_URL`, `DB_*`, `MAIL_*`,
   `QUEUE_CONNECTION=database`, `CACHE_STORE=database`,
   `SESSION_DRIVER=database`.
4. Run:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan key:generate
   php artisan migrate --force
   php artisan storage:link
   php artisan optimize
   php artisan app:create-super-admin
   ```
5. Add **one cron job, every minute**:
   ```bash
   * * * * * cd /home/USER/app && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
   This single cron runs every scheduled task **and** works the queue for
   ~55 seconds per minute.
6. Open `/admin` → System health and confirm the scheduler heartbeat.

## 5.3 Installation summary (VPS)

- PHP 8.4, PostgreSQL 16, Redis 7, Nginx, Supervisor.
- `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`.
- Run `php artisan horizon` under Supervisor; add the same
  `schedule:run` cron. Horizon dashboard at `/horizon` (super admins).
- Tune worker pools with `HORIZON_DEFAULT_PROCESSES`,
  `HORIZON_SENDING_PROCESSES`, `HORIZON_SENDING_PRIORITY_PROCESSES`,
  `HORIZON_IMAP_PROCESSES`, `HORIZON_BULK_PROCESSES`.
- Read replicas: `DB_READ_HOST=10.0.0.11,10.0.0.12` (reads go to replicas;
  writes and reads right after a write use `DB_HOST`).

## 5.4 Updating to a new version

```bash
cd /path/to/app
php artisan down            # optional maintenance page
git pull                    # or upload the new release
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear && php artisan optimize
php artisan up
```

On a VPS also run `php artisan horizon:terminate` so workers pick up the new
code. After updating, check System health and, if new modules were added,
add them to your plans.

## 5.5 Backups

Back up daily:

- **The database** (`mysqldump` / `pg_dump`, or cPanel Backup).
- **`.env`** (contains `APP_KEY`; without it, encrypted mailbox passwords
  and API keys can't be read).
- **`storage/app`** (uploaded logos, imports, and
  `storage/app/private/archives/email-events/` with archived tracking events).

Test a restore occasionally.

## 5.6 Data retention and archiving

- Raw open/click events older than `ARCHIVE_EVENTS_AFTER_DAYS` (default 180)
  are moved hourly to gzipped files under
  `storage/app/private/archives/email-events/YYYY-MM/`. Read them with
  `zcat`. Analytics are not affected. `0` disables archiving.
- Warmup messages older than 90 days, integration delivery logs older than
  30 days and old failed jobs are pruned daily.

## 5.7 Security checklist

- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS everywhere.
- Keep `MAILBOX_ALLOW_PRIVATE_HOSTS=false` (blocks connections to internal
  network addresses through mailbox settings).
- Use strong passwords and 2FA for super admins; limit who is super admin.
- Keep `.env` out of the web root and backups encrypted.
- Update regularly (security fixes in Laravel/Filament).

---

# Part 6: Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Campaign launched but nothing sends | Outside the sending window, daily limits reached, mailboxes paused, or the cron isn't running | Open the campaign's **Sending status**; it names the reason. Check System health for the scheduler heartbeat. |
| "Queued jobs have waited over 5 minutes" | Cron/queue worker not running | Check the cron command and PHP path; on VPS check Horizon/Supervisor. |
| Mailbox shows **Error** | Wrong password, app password needed, OAuth access revoked, server blocks the login | Edit the mailbox, re-enter credentials or reconnect with Google/Microsoft, **Test connection**. |
| Gmail login fails with the normal password | Google requires an app password for SMTP/IMAP | Create an app password, or use *Sign in with Google*. |
| Replies don't appear in the Unibox | IMAP not reachable, wrong IMAP settings, or reply sent to another address | Check the mailbox's IMAP error; confirm port 993 is allowed outbound by your host. Only replies to campaign/one-off emails are imported. |
| Mailbox or campaign **auto-paused** | Bounce rate > 5% or complaint rate > 0.3% in 7 days | Clean the list (verify, remove bad sources), then Resume. |
| Low inbox rate in warmup / placement test | DNS problems, blacklisting, spammy content, new domain | Fix DNS, check blacklists, simplify content (plain text, fewer links), keep warming. |
| Emails land in spam | Same as above; open/click tracking on the shared domain | Use a custom tracking domain or turn tracking off; send plain text. |
| Campaign can't launch: "AI content needs approval" | AI variables without a fallback | Approve in **AI review**, or add a fallback `{{ai_first_line|...}}`. |
| Campaign can't launch: mailing address | Workspace address missing | Workspace settings → Mailing address. |
| Feature shows "Soon" or a lock | Module off, or not in the plan | Platform owner: enable the module and/or add it to the plan. |
| Invitation or password emails never arrive | System mail (`MAIL_*`) misconfigured or the cron isn't running | Fix `MAIL_*` settings; notifications are queued, so the cron must run. |
| "500 Server Error" or "Error while loading page" right after an update | `migrate` or `optimize:clear` wasn't run after `git pull` | Run `php artisan migrate --force`, `php artisan optimize:clear`, `php artisan optimize`. System health → Deployment shows what's missing. |
| Pages have no styling | Filament assets not published | `php artisan filament:assets`. |
| Custom domain doesn't verify | CNAME missing or not yet propagated | Check with `dig CNAME app.youragency.com`; wait for DNS; make sure the host serves the domain with SSL. |
| Lead finder says it isn't set up | `HUNTER_API_KEY` missing | Add the key to `.env`, run `php artisan optimize`. |
| Placement test: "No seed mailboxes" | `PLACEMENT_SEED_WORKSPACE` not set | Set up the seed workspace (see 4.7) or include your own mailboxes. |
| Blacklist shows "couldn't check" | Spamhaus refuses public DNS resolvers | Not a listing. Use a server with its own resolver for accurate checks. |
| A payment shows paid at the gateway but the invoice is open | Webhook not reaching the app | Check the webhook URL in the gateway dashboard; mark it paid manually in Billing → Invoices after confirming. |

**Logs:** `storage/logs/laravel.log`. Failed background jobs: Horizon (VPS)
or `php artisan queue:failed` (cPanel); retry with `php artisan queue:retry all`.

---

# Appendix A: Settings reference (.env)

Change `.env`, then run `php artisan optimize:clear && php artisan optimize`.

### Core

| Variable | Purpose |
|---|---|
| `APP_NAME`, `APP_URL` | Product name and public URL. |
| `APP_ENV`, `APP_DEBUG` | `production` / `false` on live servers. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database. |
| `DB_READ_HOST` | Read replica hosts (comma-separated). |
| `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` | `database` on cPanel, `redis` on VPS. |
| `QUEUE_RUN_FROM_SCHEDULER` | Let the cron work the queue (default on with the database queue). |
| `MAIL_*`, `MAIL_FROM_ADDRESS` | System email (invites, password resets, reports). |

### Sending and deliverability

| Variable | Default | Purpose |
|---|---|---|
| `MAILBOX_CONNECT_TIMEOUT` | 10 | Seconds before an SMTP/IMAP connection gives up. |
| `MAILBOX_ALLOW_PRIVATE_HOSTS` | false | Keep false in production. |
| `TRACKING_CNAME_TARGET` | `track.<APP_URL host>` | Where custom tracking domains point. |
| `TRACKING_SCHEME` | https | Scheme for tracking links. |
| `AUTO_PAUSE_ENABLED` | true | Auto-pause on high bounces/complaints. |
| `AUTO_PAUSE_BOUNCE_RATE` | 5 | Percent, last 7 days. |
| `AUTO_PAUSE_COMPLAINT_RATE` | 0.3 | Percent, last 7 days. |
| `WARMUP_POOL` | global | `global` (all workspaces) or `workspace` (each workspace alone). |
| `WARMUP_FOLDER` | Warmup | Folder warmup emails are filed in. |
| `PLACEMENT_SEED_WORKSPACE` | – | Slug or id of the seed mailbox workspace. |
| `ARCHIVE_EVENTS_AFTER_DAYS` | 180 | Archive raw events older than this (0 = never). |

### Mailbox sign-in, AI and data services

| Variable | Purpose |
|---|---|
| `GOOGLE_MAIL_CLIENT_ID`, `GOOGLE_MAIL_CLIENT_SECRET` | Sign in with Google. |
| `MICROSOFT_MAIL_CLIENT_ID`, `MICROSOFT_MAIL_CLIENT_SECRET`, `MICROSOFT_MAIL_TENANT` | Sign in with Microsoft. |
| `ANTHROPIC_API_KEY`, `AI_PLATFORM_MODEL`, `AI_PLATFORM_MONTHLY_TOKENS`, `AI_TIMEOUT` | Included AI credits. |
| `EMAIL_VERIFICATION_PROVIDER`, `EMAIL_VERIFICATION_API_KEY` | Mailbox-level verification service. |
| `HUNTER_API_KEY` | Lead finder. |

### Billing

| Variable | Purpose |
|---|---|
| `BILLING_ENABLED` | Sell plans (false = free and unlimited). |
| `BILLING_GRACE_DAYS` | Days after a missed renewal before sending stops (default 7). |
| `BILLING_INVOICE_PREFIX`, `BILLING_SELLER_NAME`, `BILLING_SELLER_ADDRESS`, `BILLING_SELLER_EMAIL` | Printed on invoices. |
| `STRIPE_ENABLED`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | Stripe. |
| `SSLCOMMERZ_ENABLED`, `SSLCOMMERZ_STORE_ID`, `SSLCOMMERZ_STORE_PASSWORD`, `SSLCOMMERZ_SANDBOX`, `SSLCOMMERZ_CURRENCY` | SSLCommerz. |
| `BKASH_ENABLED`, `BKASH_APP_KEY`, `BKASH_APP_SECRET`, `BKASH_USERNAME`, `BKASH_PASSWORD`, `BKASH_SANDBOX` | bKash. |
| `BILLING_MANUAL_ENABLED`, `BILLING_MANUAL_LABEL`, `BILLING_MANUAL_INSTRUCTIONS` | Bank transfer / manual payment. |
| `BILLING_USD_TO_BDT`, `BILLING_EUR_TO_BDT`, `BILLING_GBP_TO_BDT` | Exchange rates for BDT gateways. |

### Agencies, website and modules

| Variable | Purpose |
|---|---|
| `CUSTOM_DOMAIN_TARGET` | Host agencies point their custom domain CNAME to (default: `APP_URL` host). |
| `MARKETING_SITE_ENABLED` | Public website at `/` (false = `/` opens the app). |
| `MARKETING_TAGLINE`, `MARKETING_DESCRIPTION`, `MARKETING_COMPANY`, `MARKETING_CONTACT_EMAIL`, `MARKETING_ADDRESS` | Website text and legal details. |
| `MODULE_*_ENABLED` | Feature switches (see 4.6). |
| `HORIZON_*_PROCESSES` | Worker pool sizes (VPS). |

---

# Appendix B: Scheduled jobs and queues

All run from the single `schedule:run` cron.

| Command | When | What it does |
|---|---|---|
| `campaigns:send` | every minute | Picks due leads per mailbox and queues one email each; creates tasks for manual steps. |
| `campaigns:ab-test` | every 10 min | Picks A/B winners once each version has enough sends. |
| `inbox:sync` | every 5 min | Checks each mailbox for replies and bounces (IMAP). |
| `warmup:send` | every 5 min | Queues due warmup emails. |
| `warmup:sync` | every 10 min | Processes received warmup emails (rescue, file, reply). |
| `placement:check` | every 5 min | Checks seed inboxes for placement test emails. |
| `domains:check` | daily 03:00 | Re-checks SPF/DKIM/DMARC/MX. |
| `domains:blacklists` | daily 04:00 | Blacklist (DNSBL) checks. |
| `reports:send` | daily 07:00 | Weekly/monthly client report emails. |
| `events:archive` | hourly | Archives old tracking events. |
| `billing:run` | hourly (billing on) | Trials, renewal invoices, overdue and expired subscriptions. |
| `queue:work` | every minute (cPanel) | Works the queues for ~55 seconds. |
| `model:prune`, `queue:prune-failed` | daily | Housekeeping. |

**Queues** (processed in this order on cPanel): `sending-priority`,
`sending`, `imap`, `default`, `ai`, `imports`.

Useful manual commands:

```bash
php artisan campaigns:send          # also prints why campaigns aren't sending
php artisan schedule:list           # what runs when
php artisan queue:failed            # failed jobs
php artisan queue:retry all         # retry them
php artisan billing:install-plans   # sample plans
php artisan app:create-super-admin  # new super admin
php artisan app:demo                # demo account
```

---

# Appendix C: Roles and permissions

| Action | Owner | Admin | Member | Client |
|---|:-:|:-:|:-:|:-:|
| View campaigns, leads, Unibox, analytics | ✓ | ✓ | ✓ | ✓ |
| Create/edit leads, lists, segments, campaigns | ✓ | ✓ | ✓ | – |
| Launch/pause campaigns, reply in Unibox | ✓ | ✓ | ✓ | – |
| Connect/edit mailboxes and domains | ✓ | ✓ | ✓ | – |
| Complete tasks, run placement tests, lead finder | ✓ | ✓ | ✓ | – |
| Team, workspace settings, branding | ✓ | ✓ | – | – |
| Integrations, API keys, AI settings | ✓ | ✓ | – | – |
| Billing, client workspaces (agency) | ✓ | ✓ | – | – |
| Be removed from the workspace or leave it | – | ✓ | ✓ | ✓ |

---

# Appendix D: Template variables and spintax

**Variables:** `{{field}}` or `{{field|fallback}}`. The fallback is used when
the lead's value is empty. Always give a fallback for optional fields.

| Variable | Value |
|---|---|
| `{{first_name}}`, `{{last_name}}`, `{{full_name}}` | Lead's name. |
| `{{email}}`, `{{company}}`, `{{title}}`, `{{phone}}` | Lead fields. |
| `{{website}}`, `{{linkedin_url}}`, `{{city}}`, `{{country}}`, `{{timezone}}` | Lead fields. |
| `{{your_custom_field}}` | Any custom field from your imports. |
| `{{sender_name}}`, `{{sender_first_name}}`, `{{sender_email}}` | The sending mailbox. |
| `{{ai_first_line}}`, `{{ai_subject}}`, `{{ai_email}}` | Approved AI content for the lead. |

**Spintax:** `{Hi|Hello|Hey}` picks one option per lead (consistently for
that lead), so emails aren't identical. Can be combined:
`{Hi|Hello} {{first_name|there}}`.

**Example:**

```
Subject: {Quick question|Question}, {{first_name|there}}

{Hi|Hello} {{first_name|there}},

I noticed {{company|your team}} is hiring for sales. How are you handling
outbound today?

Worth a 15-minute chat next week?

{{sender_first_name}}
```

---

# Appendix E: REST API and webhooks

Full reference: **`docs/API.md`**.

- **Base URL:** `https://YOUR-APP/api/v1`
- **Auth:** `Authorization: Bearer oak_...` (key from Settings → API keys).
- **Rate limit:** 120 requests per minute per key.

| Method | Endpoint | Access |
|---|---|---|
| GET | `/me` | read |
| GET | `/leads`, `/leads/{id}` | read |
| POST | `/leads` (create or update by email) | write |
| PATCH / DELETE | `/leads/{id}` | write |
| GET / POST | `/lists` | read / write |
| GET | `/campaigns`, `/campaigns/{id}` (with stats) | read |
| POST | `/campaigns/{id}/pause`, `/campaigns/{id}/resume` | write |
| GET | `/replies` (with AI category) | read |
| POST | `/suppressions` | write |

**Example:**

```bash
curl -X POST https://YOUR-APP/api/v1/leads \
  -H "Authorization: Bearer oak_xxx" -H "Content-Type: application/json" \
  -d '{"email":"jane@acme.com","first_name":"Jane","company":"Acme","list_names":["Webinar"]}'
```

**Signed webhooks:** each request carries
`X-Outreach-Signature: t=<timestamp>,v1=<hmac>`, where `v1` is the
HMAC-SHA256 of `"<timestamp>.<raw body>"` with the integration's secret.
Verify it and reject old timestamps.

---

# Appendix F: Deliverability checklist

Before the first send:

- [ ] Separate sending domain, at least a few weeks old.
- [ ] SPF, DKIM and DMARC all pass (Domains page green).
- [ ] Not on any blacklist.
- [ ] Custom tracking domain verified, or tracking off.
- [ ] Mailboxes warmed for 2–4 weeks; warmup inbox rate above 90%.
- [ ] Inbox placement test mostly inbox.
- [ ] Mailing address set.

For every campaign:

- [ ] List verified; bounce rate expected under 3%.
- [ ] 30–50 cold emails per mailbox per day at most; more mailboxes for more volume.
- [ ] Short, personal first email; no attachments or images; few links.
- [ ] Spam check clean; plain text preferred.
- [ ] Fallbacks on every variable; preview several leads.
- [ ] Follow-ups in the same thread, 3–5 days apart.

Every week:

- [ ] Bounce and complaint rates in Analytics.
- [ ] Warmup inbox rate per mailbox.
- [ ] Unibox worked daily: answer interested replies within hours.

---

# Appendix G: Glossary

| Term | Meaning |
|---|---|
| **Bounce (hard/soft)** | Undeliverable email. Hard = permanent (address doesn't exist); the address is suppressed. Soft = temporary (mailbox full); retried. |
| **Complaint** | The recipient clicked "Report spam". Very damaging; the lead is suppressed. |
| **DKIM / SPF / DMARC** | DNS records proving your mail is genuinely from your domain. |
| **DNSBL / blacklist** | Public lists of known spam sources that providers consult. |
| **Inbox rate** | Share of delivered emails that reached the inbox rather than spam. |
| **IMAP / SMTP** | Protocols to read (IMAP) and send (SMTP) email. |
| **List-Unsubscribe** | Email header giving inboxes a one-click unsubscribe button. |
| **OAuth** | Signing in with Google/Microsoft without sharing a password. |
| **Sending window** | Days and hours when a campaign or mailbox may send. |
| **Seed mailbox** | A test inbox used to measure placement. |
| **Spintax** | `{a|b|c}` syntax for random variations of text. |
| **Suppression** | Permanent do-not-contact entry. |
| **Tenant / workspace** | A customer's isolated data space. |
| **Warmup** | Gradual reputation building through real email exchanges. |
