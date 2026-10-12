# REST API (v1)

Base URL: `https://your-app/api/v1` · JSON in, JSON out.

## Authentication

Create a key in **Settings → API keys** (owners and admins). Send it as a
bearer token:

```bash
curl https://your-app/api/v1/me -H "Authorization: Bearer oak_..."
```

Keys belong to one workspace and have abilities: `read` (GET) and `write`
(everything else). Limit: 120 requests per minute per key. Errors:
`401` bad/expired key, `403` missing ability / suspended workspace / plan
without the API, `404` not found (or another workspace's), `422` validation.

## Endpoints

| Method | Path | Ability | |
|---|---|---|---|
| GET | `/me` | read | The key's workspace |
| GET | `/leads` | read | `?email=` `?status=` `?list_id=` `?updated_since=` `?per_page=` (max 100) `?page=` |
| GET | `/leads/{id}` | read | |
| POST | `/leads` | write | Create, or update the lead with the same email |
| PATCH | `/leads/{id}` | write | |
| DELETE | `/leads/{id}` | write | |
| GET | `/lists` | read | |
| POST | `/lists` | write | `{"name": "..."}` (returns the existing list if the name is taken) |
| GET | `/campaigns` | read | |
| GET | `/campaigns/{id}` | read | With stats: sent, opened, clicked, replied, bounced, unsubscribed |
| POST | `/campaigns/{id}/pause` | write | |
| POST | `/campaigns/{id}/resume` | write | |
| GET | `/replies` | read | `?since=` `?category=` (AI category, e.g. `interested`) |
| POST | `/suppressions` | write | `{"entry": "person@acme.com"}` or a domain |

### Lead fields

`email`, `first_name`, `last_name`, `company`, `title`, `phone`, `website`,
`linkedin_url`, `city`, `country`, `timezone`, `status` (`new`, `contacted`,
`replied`, `interested`, `meeting_booked`, `not_interested`, `closed`, ...),
`custom_fields` (object, merged), `list_ids` (array), `list_names` (array,
created if new), `tags` (array, replaces the lead's tags).

```bash
curl -X POST https://your-app/api/v1/leads \
  -H "Authorization: Bearer oak_..." -H "Content-Type: application/json" \
  -d '{"email":"jane@acme.com","first_name":"Jane","company":"Acme","list_names":["Webinar"],"tags":["hot"]}'
```

## Webhooks

**Settings → Integrations → Add integration → Webhook.** Events:
`email.sent`, `email.opened`, `email.clicked`, `email.bounced`,
`lead.replied`, `reply.interested`, `reply.classified`, `lead.unsubscribed`.

Each delivery is a POST with the JSON payload:

```json
{
  "event": "reply.interested",
  "occurred_at": "2026-10-16T09:30:00+00:00",
  "workspace": {"id": 1, "name": "Softorio"},
  "lead": {"id": 42, "email": "jane@acme.com", "first_name": "Jane", "status": "interested", "custom_fields": {}},
  "campaign": {"id": 7, "name": "Clinics Q4"},
  "reply": {"subject": "Re: quick question", "text": "...", "category": "meeting_request", "summary": "Wants a call on Tuesday."}
}
```

Headers: `X-Outreach-Event` and `X-Outreach-Signature: t=<unix>,v1=<hex>`,
where `v1 = HMAC-SHA256("<t>.<raw body>", signing secret)` (see "Signing
secret" on the integration). Non-2xx responses are retried (30 s, 2 min,
10 min) for 5xx/429; after 20 failures in a row the integration is switched
off and owners are notified.

**Zapier / Make / n8n:** use a "Catch Hook" / "Custom webhook" URL as the
webhook URL, and the REST API with an API key for actions (create lead,
pause campaign, suppress).
