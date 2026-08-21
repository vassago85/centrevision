# Security alert email (slice 1+3)

**Date:** 2026-08-21  
**Status:** Approved for planning  
**Scope:** Outbound email alerts + configurable rules  
**Out of scope:** Webhooks/SMS/WhatsApp, partner API (#5), vehicle profile (#2), lot capacity (#4)

## Goal

Owners and security operators get email when configured security rules fire, without flooding inboxes. Site desk settings + per-user opt-in. Shops never receive plate-level alerts.

## Decisions locked

| Topic | Choice |
|---|---|
| Delivery order | (1+3) → 2 → 4; API skipped |
| Recipients | Site alert list + per-user email opt-in |
| Channels | Email only |
| Rules | Watchlist, dwell, odd-hour, multi-entry — all configurable |
| Quiet hours | Site-configurable; per-rule `respect_quiet` |
| Engine | Event-driven (A) + short scheduled sweep for pattern rules |

## Architecture

```
Plate ingest / visit match
        │
        ▼
 AlertEvaluator (live: watchlist, dwell)
        │
        ▼
 alert_events (dedupe by fingerprint)
        │
        ├── quiet hours + respect_quiet? → status=pending, send_after=window end
        └── else → queue SendAlertMail → status=sent|failed

Scheduler (~15 min)
        │
        ├── Evaluate pattern rules (odd_hour, multi_entry) via SecurityAnalytics
        └── Flush pending alert_events where send_after <= now
```

### Live path

Hook after visit matching (same place watchlist hits / open visits become known):

1. Load site alert settings; if `alerts.enabled` is false, stop.
2. For each enabled live rule, evaluate against the new/updated visit or plate event.
3. Insert `alert_events` if fingerprint is new; skip on unique conflict.
4. Resolve send time; queue mail or leave pending.

### Scheduled path

Artisan command / scheduled job every 15 minutes:

1. For each site with alerts enabled, run odd-hour + multi-entry evaluators when those rules are enabled.
2. Create `alert_events` with fingerprints that include a time bucket (e.g. calendar day for multi_entry; rolling window id for odd_hour).
3. Flush pending rows ready to send.

## Data model

### `alert_events`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| organization_id | FK | Denormalised for tenancy queries |
| site_id | FK | |
| rule | string | `watchlist_hit`, `dwell`, `odd_hour`, `multi_entry` |
| plate_number | string | Normalised |
| visit_id | nullable FK | |
| watchlist_plate_id | nullable FK | |
| fingerprint | string | Unique with site_id |
| status | string | `pending`, `sent`, `suppressed`, `failed` |
| payload | json | Email-ready snapshot |
| detected_at | datetime | |
| send_after | nullable datetime | Null = send ASAP when processed |
| sent_at | nullable datetime | |
| error | nullable text | Last failure message |

**Indexes:** unique `(site_id, fingerprint)`; `(status, send_after)` for flusher.

**Fingerprint recipes**

- `watchlist_hit`: `{site}|watchlist|{plate}|{floor(unix/dedupe_minutes)}` — `dedupe_minutes` default 60
- `dwell`: `{site}|dwell|{visit_id}` (one email per open visit until closed; ignore `dedupe_minutes`)
- `multi_entry`: `{site}|multi|{plate}|{Y-m-d}`
- `odd_hour`: `{site}|odd|{plate}|{window_start_date}` (window from `trafficflow.security.odd_hour_window_days`)

### Site settings (`sites.settings.alerts`)

```json
{
  "enabled": true,
  "recipients": ["desk@centre.example"],
  "quiet_start": "22:00",
  "quiet_end": "06:00",
  "dedupe_minutes": 60,
  "dwell_hours": null,
  "multi_entry_threshold": null,
  "rules": {
    "watchlist_hit": { "enabled": true, "respect_quiet": false },
    "dwell": { "enabled": true, "respect_quiet": true },
    "odd_hour": { "enabled": true, "respect_quiet": true },
    "multi_entry": { "enabled": true, "respect_quiet": true }
  }
}
```

Defaults when key absent: alerts off until owner enables; if enabled with empty rules map, treat all four as enabled with watchlist `respect_quiet=false` and others `true`. Empty quiet times = quiet hours off. `dwell_hours` / `multi_entry_threshold` null → existing site/config defaults.

### Users

- Column `alert_email_opt_in` (boolean, default false in DB).
- On create: set **true** for `security_operator`, **false** for `owner_admin` (and other roles).
- Existing security operators: one-time migration/data step sets opt-in true.

### Mail

- Mailable: `SecurityAlertMail`.
- One email per event (no batching in v1).
- Body: site name, rule label, plate, detected time, short context from payload (watchlist kind/reason, dwell hours, entry count), deep link to Security (or Activity filtered by plate if easy).
- No plate images, no CSV attachments, no other plates in the same email (POPIA).

## Recipients

Union of:

1. Site `alerts.recipients` (validated emails, cap same spirit as report recipients — max 10).
2. Users in the site’s organization with role `owner_admin` or `security_operator`, `alert_email_opt_in=true`, verified email.

Deduplicate by email address. If the union is empty, mark event `suppressed` with error/reason `no_recipients` (do not retry forever).

## Quiet hours

- Site local timezone (`sites.timezone`, fallback app timezone).
- If `quiet_start`/`quiet_end` set and rule has `respect_quiet=true` and `detected_at` falls in the window → `status=pending`, `send_after` = next quiet-end.
- If quiet hours off or `respect_quiet=false` → send immediately (queue).
- Default: defer, do not drop.

## UI

### Settings (owner admin)

New **Alerts** card:

- Master enable
- Recipient list (comma-separated, like reports)
- Quiet start/end (optional time inputs; clear = off)
- Per-rule toggles + “Respect quiet hours”
- Dwell hours override (optional; placeholder shows site dwell default)
- Multi-entry threshold override (optional)

### Account → Security

Toggle: “Email me security alerts” bound to `alert_email_opt_in`. Visible to owner admins and security operators only.

### Security page

Small “Recent alert emails” panel: last N `alert_events` for current site scope (rule, plate, status, time). Owner admin + security operator. No shop access (`PlateDataPolicy` / existing role middleware).

## Components (bounded units)

| Unit | Responsibility |
|---|---|
| `AlertRule` enum | Rule ids + labels |
| `AlertSettings` | Read/normalize site alerts JSON |
| `AlertEvaluator` | Live + scheduled evaluation → create events |
| `AlertFingerprint` | Build fingerprint strings |
| `AlertRecipientResolver` | Site list ∪ opted-in users |
| `AlertQuietHours` | In-window? / next send_after |
| `SendAlertMail` job | Send + update status |
| `FlushPendingAlertEvents` command | Pending → queue |
| `EvaluatePatternAlertRules` command | Odd-hour / multi-entry sweep |
| Livewire Settings / Account / Security bits | UI only |

## Error handling

- Mail failure: `status=failed`, store truncated error; job retries use Laravel queue retries; after final failure leave `failed` (manual/ops can requeue later — no auto UI in v1).
- Unique fingerprint race: catch and no-op.
- Site without exit tracking: dwell rule never fires (same honesty as Security page).
- Disabled master switch: evaluators no-op; pending flusher still runs so enabling later doesn’t strand old deferred mail unless we also check enabled at flush — **at flush, skip/suppress if alerts disabled**.

## Testing (Pest)

- Fingerprint uniqueness / dedupe
- Quiet hours deferral and `respect_quiet=false` bypass
- Recipient union + empty → suppressed
- Watchlist live create on entry when enabled
- Dwell creates one event per visit
- Pattern job creates multi_entry / odd_hour when thresholds met
- Shop user cannot load alert log / settings alerts
- Mailable contains plate + rule, no extra plates
- Opt-in defaults by role

## Rollout

1. Migrate `alert_events` + `users.alert_email_opt_in`.
2. Ship settings + account toggle dark (alerts default off).
3. Wire evaluator + jobs + schedule.
4. Security recent-log panel.
5. Docs note in `docs/` only if needed for operators (optional).

## Follow-on (separate specs)

- **#2 Vehicle profile** — plate detail page from Activity/Security.
- **#4 Lot capacity %** — site capacity setting + occupancy KPI.
- **#5 Partner API** — explicitly deferred / skipped for now.

## Non-goals

- Webhooks, SMS, WhatsApp
- Batched digest emails
- Custom rule expression builder
- Barrier / parking payment integration
- Alerting shop users
