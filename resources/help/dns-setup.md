---
title: DNS setup (SPF, DKIM, DMARC)
summary: The three records that decide whether you land in the inbox.
order: 3
---
Mailbox providers check three DNS records on your sending domain. Add them where your domain's DNS is managed (Cloudflare, cPanel Zone Editor, your registrar).

## SPF

One TXT record on the domain listing who may send for it. Examples:

- Google Workspace: `v=spf1 include:_spf.google.com ~all`
- Microsoft 365: `v=spf1 include:spf.protection.outlook.com ~all`

Only **one** SPF record per domain: combine providers into one line.

## DKIM

A signing key published as a TXT record (e.g. `google._domainkey`). Generate it in your email provider's admin console, add the record, then turn signing on there.

## DMARC

A TXT record at `_dmarc.yourdomain.com`, for example:

`v=DMARC1; p=none; rua=mailto:dmarc@yourdomain.com`

Start with `p=none`, move to `quarantine` once everything passes.

## Check it

Infrastructure → Sending domains → **Check now**. DNS changes can take up to a few hours to show.

**Tip:** send cold email from a separate domain (e.g. `getacme.com` instead of `acme.com`) so your main domain's reputation stays safe.
