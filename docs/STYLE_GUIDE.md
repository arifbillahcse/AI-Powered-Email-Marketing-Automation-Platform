# Style Guide

Clean, calm, data-dense SaaS UI. Light mode by default, dark mode supported.

## Colors

Panel colors are set in `AppPanelProvider` using Filament's palettes.

| Role | Filament palette | Base hex | Use |
|---|---|---|---|
| primary | Indigo | `#4F46E5` | Buttons, links, active nav, focus |
| success | Emerald | `#10B981` | Replied, healthy, active |
| warning | Amber | `#F59E0B` | Paused, warming up, near a limit |
| danger | Rose | `#F43F5E` | Bounced, blacklisted, errors |
| info | Sky | `#0EA5E9` | Opened, tips |
| gray | Zinc | `#71717A` | Text, borders, neutral badges |
| AI accent | `ai-*` (theme.css) | `#8B5CF6` | Anything AI-generated |

The `/admin` panel uses **Slate** as primary so it's never confused with `/app`.

### Email status badges
| Status | Color |
|---|---|
| Sent | gray |
| Opened | info |
| Clicked | primary |
| Replied, Interested | success |
| Bounced | danger |
| Unsubscribed | warning |
| Paused, Draft | gray |

### Inbox health score
| Score | Color | Label |
|---|---|---|
| 80–100 | success | Healthy |
| 50–79 | warning | Needs attention |
| < 50 | danger | At risk (auto-pause) |

## Typography
- UI: **Inter**, 14px base
- Mono: **JetBrains Mono** for DNS records, API keys, `{{variables}}` (`.code-token`)
- Page titles 24–30px, stat numbers 28px semibold

## Layout
- Collapsible sidebar, full-width content
- Nav groups: **Outreach** (Campaigns, Leads, Unibox), **Infrastructure**
  (Email Accounts, Domains, Warmup), **Insights** (Analytics), **Settings**
- Dashboard: row of 4 KPI stat widgets with sparklines

## Components
- Radius: 8px inputs/buttons, 12px cards (Filament defaults)
- Tables: compact, status badges, bulk actions
- AI content: `.ai-badge` (✨ label) or `.ai-gradient` for AI action buttons
- Locked modules: stay in navigation with a gray "Soon" badge and a locked empty state
- Empty states: icon, one-line explanation, primary action
