# Security Alert Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Configurable security alert rules that email site recipients and opted-in users when watchlist, dwell, odd-hour, or multi-entry conditions fire.

**Architecture:** Event-driven `AlertEvaluator` after visit matching for watchlist + dwell; scheduled jobs for pattern rules and flushing quiet-hour deferred mail. Persist every firing in `alert_events` for dedupe, history, and POPIA-safe audit.

**Tech Stack:** Laravel 12, Livewire/Volt pages, queued Mailables, Pest, existing `sites.settings` JSON, Spatie roles.

## Global Constraints

- Email channel only (no webhooks/SMS).
- Shops never receive or see plate-level alerts.
- One email per `alert_events` row; no plate images or multi-plate dumps.
- Alerts default **off** until owner enables in Settings.
- Quiet hours default: defer (not drop); watchlist defaults `respect_quiet=false`.
- Follow existing code style: focused classes under `app/Support/Alerts/`, Pest feature tests, PowerShell-safe git commits (`git commit -m "..."`).

## File map

| Path | Responsibility |
|---|---|
| `database/migrations/*_create_alert_events_table.php` | `alert_events` schema |
| `database/migrations/*_add_alert_email_opt_in_to_users_table.php` | User opt-in + backfill operators |
| `app/Enums/AlertRule.php` | Rule ids + labels |
| `app/Enums/AlertEventStatus.php` | pending/sent/suppressed/failed |
| `app/Models/AlertEvent.php` | Eloquent model |
| `app/Support/Alerts/AlertSettings.php` | Normalize site alerts JSON |
| `app/Support/Alerts/AlertFingerprint.php` | Fingerprint strings |
| `app/Support/Alerts/AlertQuietHours.php` | In window? / send_after |
| `app/Support/Alerts/AlertRecipientResolver.php` | Site emails ∪ opted-in users |
| `app/Support/Alerts/AlertEvaluator.php` | Create events + queue/defer |
| `app/Jobs/SendAlertMail.php` | Send mailable, update status |
| `app/Jobs/EvaluatePatternAlertRules.php` | Odd-hour + multi-entry sweep |
| `app/Jobs/FlushPendingAlertEvents.php` | Pending → SendAlertMail |
| `app/Mail/SecurityAlertMail.php` + `resources/views/mail/security-alert.blade.php` | Email content |
| `app/Jobs/MatchVisits.php` | Hook evaluator after open/close |
| `routes/console.php` | Schedule pattern + flush every 15 min |
| `resources/views/pages/settings.blade.php` | Alerts card |
| `resources/views/pages/account/security.blade.php` | Opt-in toggle |
| `resources/views/pages/security.blade.php` | Recent alert emails panel |
| `app/Models/User.php` + `UserFactory` | Cast/default opt-in |
| `app/Models/Site.php` | DEFAULT_SETTINGS alerts key (optional) |
| `tests/Feature/Alerts/*` | Pest coverage |

**Follow-on (same effort, separate mini-plans after this ships):** vehicle profile (#2), lot capacity (#4). API skipped.

---

### Task 1: Schema, enums, model

**Files:**
- Create: migrations, `AlertRule`, `AlertEventStatus`, `AlertEvent`
- Modify: `User.php`, `UserFactory.php`
- Test: `tests/Feature/Alerts/AlertEventSchemaTest.php`

- [ ] **Step 1: Write failing schema/model test**

```php
<?php
use App\Enums\AlertEventStatus;
use App\Enums\AlertRule;
use App\Models\AlertEvent;
use App\Models\Organization;
use App\Models\Site;

it('persists an alert event with rule and status enums', function () {
    $org = Organization::factory()->owner()->create();
    $site = Site::factory()->for_($org)->create();

    $event = AlertEvent::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'rule' => AlertRule::WatchlistHit,
        'plate_number' => 'BX91GP',
        'fingerprint' => $site->id.'|watchlist|BX91GP|1',
        'status' => AlertEventStatus::Pending,
        'payload' => ['kind' => 'block'],
        'detected_at' => now(),
    ]);

    expect($event->fresh()->rule)->toBe(AlertRule::WatchlistHit)
        ->and($event->status)->toBe(AlertEventStatus::Pending);
});
```

- [ ] **Step 2: Run test — expect FAIL (class not found)**

Run: `php artisan test --filter=AlertEventSchemaTest`

- [ ] **Step 3: Implement migration + enums + model**

`alert_events`: organization_id, site_id, rule, plate_number, visit_id nullable, watchlist_plate_id nullable, fingerprint, status, payload json, detected_at, send_after nullable, sent_at nullable, error nullable text; unique(site_id, fingerprint); index(status, send_after).

`users.alert_email_opt_in` boolean default false; data migration: set true where role = security_operator.

`User` cast `alert_email_opt_in` => boolean; on `booted` creating: if SecurityOperator and not explicitly set, true.

- [ ] **Step 4: Run test — PASS**

- [ ] **Step 5: Commit** — `git commit -m "Add alert_events schema and user email opt-in"`

---

### Task 2: Settings, fingerprint, quiet hours, recipients

**Files:**
- Create: `AlertSettings`, `AlertFingerprint`, `AlertQuietHours`, `AlertRecipientResolver`
- Test: `tests/Feature/Alerts/AlertSupportTest.php`

**Interfaces:**
- `AlertSettings::for(Site $site): self` with `enabled(): bool`, `recipients(): array`, `ruleEnabled(AlertRule): bool`, `respectQuiet(AlertRule): bool`, `dedupeMinutes(): int`, `dwellHours(): int`, `multiEntryThreshold(): int`, `quietStart(): ?string`, `quietEnd(): ?string`
- `AlertFingerprint::make(Site, AlertRule, string $plate, array $context): string`
- `AlertQuietHours::sendAfter(Site, AlertSettings, AlertRule, CarbonInterface $detectedAt): ?CarbonInterface` — null means send ASAP
- `AlertRecipientResolver::emails(Site): array<string>`

- [ ] **Step 1: Write failing unit-style feature tests** for fingerprint dwell uniqueness, quiet deferral, empty recipients, opt-in union

- [ ] **Step 2: Implement the four support classes**

Default rules when `alerts.enabled` true and rules missing: all four enabled; watchlist respect_quiet false; others true.

- [ ] **Step 3: Tests PASS → commit** — `Add alert settings fingerprint quiet hours and recipients`

---

### Task 3: Evaluator + MatchVisits hook + mail job

**Files:**
- Create: `AlertEvaluator`, `SendAlertMail`, `SecurityAlertMail`, mail view
- Modify: `MatchVisits.php` — after successful `openVisit` create (and after dwell-relevant path), call evaluator
- Test: `tests/Feature/Alerts/AlertEvaluatorTest.php`

**Interfaces:**
- `AlertEvaluator::record(Site $site, AlertRule $rule, string $plate, array $payload, ?int $visitId = null, ?int $watchlistPlateId = null): ?AlertEvent`
- `AlertEvaluator::evaluateWatchlistHit(Site, PlateEvent|Visit context): void`
- `AlertEvaluator::evaluateDwellForSite(Site): void` — open visits over threshold
- Live hook: after `Visit::create` in `openVisit`, call watchlist evaluation for that plate/site

Dwell: also run from scheduled pattern job (visits age without new events). On MatchVisits `orphanStaleVisits` end, optionally call `evaluateDwellForSite`.

Send path inside `record`: if no recipients → suppressed; else if sendAfter set → pending; else dispatch `SendAlertMail`.

- [ ] **Steps:** failing tests (watchlist creates+mails; dedupe; no recipients suppressed; alerts disabled no-op) → implement → PASS → commit `Wire alert evaluator and security alert mail`

---

### Task 4: Pattern + flush schedules

**Files:**
- Create: `EvaluatePatternAlertRules`, `FlushPendingAlertEvents`
- Modify: `routes/console.php` — everyFifteenMinutes both jobs withoutOverlapping
- Test: `tests/Feature/Alerts/AlertPatternJobsTest.php`

Pattern job: for each site with alerts enabled, use `SecurityAnalytics` (instantiate carefully — may need site-scoped tenancy or query without relying on request tenancy). Prefer passing `$site` into analytics methods or duplicate thin queries in evaluator to avoid Tenancy coupling.

Flush: `AlertEvent::query()->where('status','pending')->where(fn => send_after null or <= now)->each` → if site alerts disabled suppress; else `SendAlertMail::dispatch`.

- [ ] **Steps:** tests for multi_entry fingerprint/day and flush after quiet → implement → commit `Schedule pattern alert evaluation and pending flush`

---

### Task 5: Settings + account UI

**Files:**
- Modify: `resources/views/pages/settings.blade.php`, `resources/views/pages/account/security.blade.php`
- Test: `tests/Feature/Alerts/AlertSettingsPageTest.php`, extend account security test if present

Settings Alerts card: enabled, recipients, quiet start/end, per-rule enabled + respect_quiet, optional dwell/multi overrides. Save into `settings.alerts` JSON.

Account: toggle `alert_email_opt_in` for owner_admin and security_operator only.

- [ ] **Steps:** Livewire tests → UI → commit `Add alerts settings and account email opt-in UI`

---

### Task 6: Security recent alerts panel

**Files:**
- Modify: `resources/views/pages/security.blade.php`
- Test: `tests/Feature/Pages/SecurityPageTest.php` (or new)

Show last 20 `alert_events` for current site (rule label, plate, status, detected_at).

- [ ] **Steps:** test asserts panel sees event → UI → commit `Show recent alert emails on Security page`

---

### Task 7: Smoke + docs touch

- [ ] Run full alert-related Pest suite + `tests/Feature/Jobs/MatchVisitsTest.php`
- [ ] Confirm schedule registered
- [ ] Commit any fixes

---

## After this plan

1. Mini-spec + implement **vehicle profile** (`/activity/plates/{plate}` or Livewire page).
2. Mini-spec + implement **lot capacity** (`sites.settings.capacity` + overview KPI %).
3. Do **not** build partner API.
