---
title: Connecting mailboxes
summary: Google, Microsoft 365 and any SMTP/IMAP inbox, including app passwords.
order: 2
---
## Google Workspace and Gmail

Click **Sign in with Google** on Email accounts. No password is stored; we use a secure token.

If you add Gmail with SMTP/IMAP instead, turn on 2-step verification and create an **app password** (Google Account → Security → App passwords). Use it in both the SMTP and IMAP password fields.

## Microsoft 365 and Outlook

Click **Sign in with Microsoft**. If your admin blocks third-party apps, ask them to allow it, or enable SMTP AUTH for the mailbox.

## Other providers (cPanel mail, Zoho, ...)

Choose "Other" and enter:

- SMTP host and port (usually 587 with STARTTLS, or 465 with SSL)
- IMAP host and port (usually 993 with SSL)
- The full email address as the username

Click **Test connection**. A green result means we can send and read replies.

## Many mailboxes at once

Use **Bulk import** with a CSV of mailboxes and their settings.
