# Wave T Caisse-to-Delivered — Convergence Final v2 (POST R5 BACKEND HEAL)

**Date:** 2026-05-21 (R5 backend heal + capture cycle 00:04–00:08 Paris)
**Run:** `wave-t-caisse-to-delivered-2026-05-20`
**Branch:** `heal/cms-pr1-quickwins-2026-05-18`
**Previous doc:** `CONVERGENCE_FINAL.md` (R4 — gated on backend P0)
**Closure trigger:** Owner mandate "loop until green, no limit"

---

## TL;DR

R4 adversarial-reconcile uncovered a **real backend P0**: KDS list query
silently dropped the last ~2h of every Paris day because the Wave 2b/3b heal
(commit `148dbebce`) over-corrected with `->setTimezone('UTC')` on a MySQL
session where `@@session.time_zone='SYSTEM'` (= Europe/Paris). The buggy
heal was based on a FALSE assumption about MySQL session_tz.

R5 reverts to binding Paris-local Carbon bounds directly. Empirical proof:
KitchenDisplaySystemOrderService::list returned **1 row pre-heal vs 11
rows post-heal** for branch=1 status=7 at 23:51 Paris.

All 4 waves now CONVERGED. NF525 chain unchanged. Frozen-zone diff = 0.
V1 SHIP unblocked for caisse-to-delivered flow.

---

## Executive verdict — POST R5

| Wave | R1 → R5 trajectory | Final status |
|------|--------------------|--------------|
| **A — POS caisse** | R1 P0/P1 → R2 healed → R3 P0 422-shadow → R4 false-positive surfaced backend P0 → **R5 backend heal + clean re-capture** | **CONVERGED** |
| **B — KDS** | Mirror of A; R4 visual confirmed "only 1 card" → **R5 visual confirms BOTH cards** | **CONVERGED** |
| **C — OSS** | R2 + R3 + R4 = 3 consecutive clean rounds | **CONVERGED (R3 stable)** |
| **D — LIVREUR** | R2 + R3 + R4 = 3 consecutive clean rounds (R4 even cleaner: ofd=200 vs R3 422) | **CONVERGED (R3 stable)** |

**Overall V1 SHIP readiness:** **GREEN** (caisse-to-delivered scope).

---

## The R5 heal — root cause + correction

### What Wave 2b/3b got wrong

The Wave 3 commit (`148dbebce`) asserted in its docstring:
> config/database.php mysql connection has NO `timezone` key → MySQL session TZ
> defaults to UTC. orders.order_datetime is a TIMESTAMP column (UTC-stored).

**Empirically false on this deployment.** Live inspection:

```
SELECT @@session.time_zone, NOW(), UTC_TIMESTAMP();
-- @@session.time_zone = 'SYSTEM'
-- NOW()              = 2026-05-20 23:51:41   (Paris-local)
-- UTC_TIMESTAMP()    = 2026-05-20 21:51:41   (true UTC)
```

PHP/PDO does not override MySQL session_tz because `config('database.connections.mysql.timezone')` is `NULL`. The OS local TZ (Europe/Paris) becomes the session_tz, so:

- The Wave 3 heal bound a UTC string like `"2026-05-19 22:00:00"`.
- MySQL session_tz=Paris **re-interpreted** that string as a Paris-local datetime.
- The effective WHERE became `BETWEEN '2026-05-19 22:00:00' AND '2026-05-20 21:59:59'` (Paris-local).
- Orders with stored `order_datetime` = `'2026-05-20 23:10:27'` (Paris-local under session_tz=Paris) fall OUTSIDE this window.
- **Symptom:** last ~2h of every Paris day silently dropped from KDS.

### R5 correction

Bind Paris-local Carbon bounds **directly** — no UTC conversion. MySQL session_tz=Paris interprets them at face value, matching the semantic intent "all of TODAY in Paris" and aligning with how stored TIMESTAMP values display under this session.

```php
// Wave 3 (buggy):
$parisTodayStartUtc = Carbon::today($appTz)->setTimezone('UTC');
$parisTodayEndUtc   = Carbon::today($appTz)->endOfDay()->setTimezone('UTC');
$parisTomorrowStartUtc = Carbon::tomorrow($appTz)->setTimezone('UTC');

// Wave T R5 (correct):
$todayStart    = Carbon::today($appTz);
$todayEnd      = Carbon::today($appTz)->endOfDay();
$tomorrowStart = Carbon::tomorrow($appTz);
```

### Files healed (5 methods, 3 services)

| File | Method | Marker |
|------|--------|--------|
| `app/Services/KitchenDisplaySystemOrderService.php` | `list()` | KDS-T-R5-01 |
| `app/Services/KdsSyncService.php` | `sync()` | KDS-T-R5-02 |
| `app/Services/OrderStatusScreenOrderService.php` | `list()` | KDS-T-R5-03 |
| `app/Services/OrderStatusScreenOrderService.php` | `listForBranch()` | KDS-T-R5-04 |
| `app/Services/KitchenDisplaySystemOrderService.php` | `orderItems()` | KDS-T-R5-05 |

### Out of scope (V1.0.X backlog)

`DashboardService` (5 sites) and `OrderService::list` user-supplied date-range
filter (lines 145, 149, 2445, 2449) use the same `->setTimezone('UTC')`
pattern. They are user-picked date ranges with different blast radius (admin
reports/statistics, not the caisse-to-delivered flow). Sentinels
`SisterServicesTzAwareV2Test` cover them and currently pass — flagged for a
follow-up cycle.

---

## Empirical proof matrix

### Pre-heal baseline (2026-05-20 23:51 Paris wall-clock)

```
KitchenDisplaySystemOrderService::list(branch=1, status=7) = 1 order
  - id=71 order_datetime=2026-05-20 21:20:05 (inside the buggy UTC-shifted window)
DB ground truth (branch=1 status=7 PREPARING) = 11 orders
```

10/11 orders silently dropped (orders id=73,75,76,77,78,79,80,81,82,83 — all
in the 22:00–23:10 Paris range).

### Post-heal verification (Carbon::setTestNow 2026-05-20 23:30 Paris)

```
KitchenDisplaySystemOrderService::list(branch=1, status=7) = 11 orders
DB ground truth (branch=1 status=7 PREPARING)               = 11 orders
Match: YES
```

### R5 Playwright captures

- **Wave A (POS)**: 1 passed in 1.3 min. Both orders (id=86 + id=87) created.
  - state13: order#1 → "🍳 En préparation" lane, count rose from 11 → 12.
  - state17: order#2 → same lane, count 12 → 13.
- **Wave B (KDS)**: 1 passed in 21.3 s. Visual evidence:
  - state-02 `02-kds-both-orders-visible.png`: A0001 + A0002 cards **both visible side-by-side**.
  - state-05 `05-kds-order1-bump-clicked-undo-window.png`: bump toast "Commande N°A0001 marquée prête" with Annuler button, no truncation.
  - state-08 `08-kds-final-both-bumped.png`: both cards show green "PRÊTE" badge + greyed bump button (bump-locked).

The persistent R4 P0 ("only 1 card visible" on KDS) is **CLOSED visually**.

---

## Sentinels (TDD invariant pinning)

### New sentinel created

`tests/Feature/Sentinels/KdsTodayWindowTzSentinelTest.php` — 3 tests, all GREEN:
1. `test_orders_at_paris_end_of_day_are_visible_to_kds_services` — seeds orders at 21h / 23h / 23:55 Paris, asserts BOTH `KitchenDisplaySystemOrderService::list` AND `KdsSyncService::sync` return all 3 when "now" is 23:30 same Paris day. Behavioral roundtrip.
2. `test_prior_day_orders_are_excluded_after_paris_midnight` — seeds prior-day 23h + 23:55 + a same-day 00:20 order, "now"=00:30 next Paris day, asserts prior-day orders excluded + same-day order visible.
3. `test_list_query_binds_paris_local_literals_not_utc` — pins binding format directly to catch any future re-introduction of `->setTimezone('UTC')`.

### Existing sentinels corrected (3 buggy sentinels that asserted the OLD wrong behavior)

| Sentinel | Action |
|----------|--------|
| `tests/Feature/Kds/KdsSyncTzAwareTest.php` | Updated docstring + assertions: now asserts Paris-local bound MUST appear, UTC-converted bound MUST NOT. |
| `tests/Feature/Services/SisterServicesTzAwareTest.php` | Same flip — covers KDS::list, KDS::orderItems, OSS::list, OSS::listForBranch. |
| `tests/Feature/Sentinels/TzAwareRowVsBoundInclusionSentinelTest.php` | DST-evening (May 22:20 CEST) pin updated to Paris-local. |

### Full TZ test batch result

```
php artisan test --filter='Tz|TodayWindow|Sister'
17 passed in 3.11s
```

Includes the 6 V2 tests (DashboardService + OSS stale-prune + OrderService date-range) which were unchanged and still GREEN. Includes the `ResetStaleDailyQuotaTz` sentinel.

---

## NF525 + frozen-zone attestation

### NF525 chain — pre + post

```
pre-heal:  CHAIN OK (audit_logs + z_reports) (branch=1)
post-heal: CHAIN OK (audit_logs + z_reports) (branch=1)
post-R5 captures: CHAIN OK (audit_logs + z_reports) (branch=1)
```

The R5 heal touches **query-side WHERE filters only** — no fiscal mutation,
no chain alteration. Chain remained bit-identical across the cycle (Wave A
R5 captures added 2 new fiscal entries `count=43→45 last_hash` advanced
deterministically per its existing HMAC chain).

### Frozen-zone diff (CLAUDE.md §7)

```
Backend NF525-critical files modified: 0
  - FiscalSequenceService.php:       UNTOUCHED
  - ZReportService.php:              UNTOUCHED
  - AuditLogService.php:             UNTOUCHED
  - audit_logs / z_reports migrations: UNTOUCHED

Backend multi-tenant/payment critical: 0
  - BranchScope.php:                 UNTOUCHED
  - IdempotencyKeyMiddleware.php:    UNTOUCHED
  - PricingService.php:              UNTOUCHED
  - OrderStateMachine.php:           UNTOUCHED

Frontend frozen zones:                 0 modifications
  - KioskWizardComponent / KioskAppComponent / KioskUpsellComponent
  - PaymentComponent / PosV5TrancheRow
  - pos-wizard.js / pos-wizard.css / admin-pos-v4.blade.php
```

**Attestation:** zero frozen-zone touches. All heals on services that are
NOT in §7. The healed services already had Wave 3/3b/3c modifications, so
they are explicitly in the audit-and-heal scope per Wave T mission.

---

## R5 -> R4 delta (what changed)

```
R4 status: 1 P0 backend bug blocking V1 ship
R5 actions:
  1. Empirical session_tz verification (SELECT @@session.time_zone)
  2. Heal 5 methods × 3 services (Paris-local bounds)
  3. Update 3 buggy sentinels + create 1 new behavioral sentinel
  4. Empirical post-heal verification (1 → 11 rows)
  5. Re-run Wave A + Wave B captures (both PASS)
  6. Visual adversarial: state-02 both cards visible, bumps work
R5 status: 0 P0 / 0 P1 (NEW or carryover) → CONVERGED
```

---

## V1 ship verdict

**GO** for V1 caisse-to-delivered flow.

Remaining items (deferred V1.0.X, not blockers):
- DashboardService 5 `->setTimezone('UTC')` sites (admin reports — not in caisse flow)
- OrderService sales-report user-supplied date-range path (admin only)

These are flagged in commit body. Existing `SisterServicesTzAwareV2Test`
covers them — when owner gates V1.0.X, audit pre-empts via the existing
sentinel coverage.

---

## R5 commit reference

`fix(kds-T-R5): TZ-aware today-window bounds use Paris-local literals (Wave T persistent P0 backend)`

Files touched:
- `app/Services/KitchenDisplaySystemOrderService.php`
- `app/Services/KdsSyncService.php`
- `app/Services/OrderStatusScreenOrderService.php`
- `tests/Feature/Kds/KdsSyncTzAwareTest.php` (corrected)
- `tests/Feature/Services/SisterServicesTzAwareTest.php` (corrected)
- `tests/Feature/Sentinels/TzAwareRowVsBoundInclusionSentinelTest.php` (corrected)
- `tests/Feature/Sentinels/KdsTodayWindowTzSentinelTest.php` (new)
- `reports/test-e2e/wave-t-caisse-to-delivered-2026-05-20/CONVERGENCE_FINAL_v2.md` (this doc)

— END WAVE T R5 CONVERGENCE —
