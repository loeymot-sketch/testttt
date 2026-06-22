# Adversarial RED — KDS Heals Wave 2c — Wave 3c dispute

Branch: `v1-0-1-hardening-2026-05-17`
Commits under review:
- `c2613cab0` — KDS+OSS sister services TZ-aware (P0 KDS-ADV3B-01 heal)
- `9ff26e12b` — KDS cadence upper cap 60s + jitter 30s (P1 KDS-ADV3B-04 heal)

Mode: hostile, read-only, file:line strict, NO cloud.

---

## 1. Heal 2c-1 (sister TZ) — VERDICT: CONVERGENT FOR DECLARED SCOPE, NEW P0 ADJACENT

Declared scope (KDS UI list/items + OSS list/listForBranch) is patched and pinned by `SisterServicesTzAwareTest.php`. But the heal narrative implies the Paris-vs-UTC bug is contained. **It is not** — five other services on the same MySQL connection (no `mysql.timezone` key; verified `config/database.php:46-47`) bind Paris-local Carbon to UTC-stored `order_datetime` TIMESTAMP columns identically.

### KDS-ADV3C-01 (P0 NEW) — DashboardService leaks 1-2h/day on every admin widget

File: `app/Services/DashboardService.php`
- Lines 68–69, 101–102, 140–141, 184–185, 271, 326: `Carbon::today()->toDateString()` — no TZ arg → Paris-local.
- Bound on lines 74–83, 107–111, 147, 158, 198, 275, 280, 327 via `whereDate('order_datetime', ...)`. Worse than `whereBetween`: MySQL compiles to `DATE(order_datetime) = 'Y-m-d'`, which (a) coerces UTC-stored TIMESTAMP through the UTC session TZ then strips the date, (b) is non-sargable.
- Blast: every `total_order`, `pending_order`, `total_sales`, `daily_orders` widget under-counts by [1–2h Paris, DST-dependent] every day. First screen the owner sees each morning is silently wrong.

### KDS-ADV3C-04 (P0 NEW) — OSS stale-prune `now()->subHours()` Paris-bound to UTC TIMESTAMP

File: `app/Services/OrderStatusScreenOrderService.php` lines 100, 217 (inside `list()` and `listForBranch()`):
`->where('order_datetime', '>=', now()->subHours((int) config('oss.stale_window_hours', 8)));`

- `now()` resolves in `app.timezone='Europe/Paris'` (`config/app.php:120`). Eloquent serializes the Carbon as `'Y-m-d H:i:s'` Paris-local string and binds raw. MySQL session = UTC → the literal is interpreted as UTC; the prune window slips by 1-2h.
- Net: prunes orders 6-7h old (winter) instead of 8h, OR keeps orders 9-10h old. Combined with the *now-correct* day boundary `whereBetween`, the heal restores one half of the day-cutover invariant while a sibling line introduces a NEW (opposite-sign) Paris-vs-UTC bug.
- Sentinel proof of gap: `SisterServicesTzAwareTest::captureOrderDatetimeQueries` captures all bindings, but `assertUtcDayBoundariesBound()` only asserts positive presence of UTC start/tomorrow. It does NOT assert that the 8h Paris-local subtraction is converted to UTC. Bug is invisible to the heal's own sentinel.

### KDS-ADV3C-02 (P1 NEW) — OrderService report list/export

File: `app/Services/OrderService.php` lines 135, 2286 — `whereDate('order_datetime', '>=', $first_date)`. `$first_date` flows from PaginateRequest; the `date_type='today'` default path consumes `Carbon::today()->toDateString()` upstream. Sales Report list + Excel + PDF (`SalesReportController.php:43, 52, 67`) hit this. Same masquerade as Dashboard.

### KDS-ADV3C-03 (P1 NEW) — Cron + AvailabilityService daily reset skew

- `app/Console/Commands/ResetStaleDailyQuotaCommand.php:36, 40` — `$today = Carbon::today();` then `whereDate('daily_reset_at', '<', $today)`. Cron at `0 0 * * *`: if Schedule runs in UTC and Carbon resolves Paris, the cutover creates a 1-2h window where rows are reset but the `<` predicate excludes them.
- `app/Services/Menu/AvailabilityService.php:60, 109, 282, 290` — same `Carbon::today()->toDateString()` pattern. Self-consistent within service, but skew surfaces if any cross-join with `order_datetime` is added (e.g. stock-rupture dashboard).

### KDS-ADV3C-05 (P2) — Sentinel DST-axis gap

`SisterServicesTzAwareTest.php:85` pins to Paris-winter (`2026-01-15 12:00:00 UTC`, +1) only. No summer pin (+2), no DST-end Oct 27 pin. Bug-class is symmetric so the 1h-vs-2h delta isn't a bug, but a refactor swapping `->setTimezone('UTC')` for `->utc()` could regress DST-end without detection (the `2026-01-15 00:00:00` shape assertion holds for any TZ offset). Defer to V1.0.2.

### KDS-ADV3C-06 (P2) — SQLite test driver masquerade still unmitigated

`phpunit.xml:39` — `DB_CONNECTION=sqlite`. SQLite has no session TZ, so the heal's compiled-SQL sentinel correctly side-steps row-count tests. ✓ But there is no CI smoke job that runs `SisterServicesTzAwareTest` against MySQL with `time_zone='+00:00'`. KDS-ADV3B-02 raised this for Wave 2b and it remains untreated for Wave 2c. **Carry forward.**

---

## 2. Heal 2c-3 (cadence upper cap) — VERDICT: CONVERGENT for KDS scope, MAJOR GAPS at sibling polling

The KDS-specific clamp is correct (`config/catalog_v15.php:91-96` + `KdsSyncService.js:469-480` + 4 PHP/4 JS sentinels). But the silent-blind misconfig vector is generic to all polling services and only KDS was patched.

### KDS-ADV3C-07 (P1 NEW) — PosSyncService.js has no upper cap

File: `resources/js/services/PosSyncService.js`
- Line 143-145: `intervalMsWhenDisconnected: this._positiveInt(cfg.intervalMsWhenDisconnected, DEFAULTS.intervalMsWhenDisconnected)`.
- `_positiveInt` (lines 415-418): `return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;`. NO ceiling. Accepts `0` → thundering herd.
- `FK_CATALOG_POS_FALLBACK_INTERVAL_MS=999999999` (config line 63) silences POS catalog refresh during WS outage for 11.5 days. Owner-side: POS shows stale stock, items 86'd at KDS still sellable.

### KDS-ADV3C-08 (P1 NEW) — OssSyncService.js has no upper cap

File: `resources/js/services/OssSyncService.js`
- Lines 116-122 use the same `_positiveInt` (lines 408-411). Both `intervalMsWhenConnected` (60_000 default) and `intervalMsWhenDisconnected` (2_000 default) accept any positive int.
- `config/catalog_v15.php:71, 76` — `FK_CATALOG_OSS_FALLBACK_CONNECTED_INTERVAL_MS` and `_DISCONNECTED_INTERVAL_MS` have NO PHP-side clamp (raw `env(..., N)`).
- Blast: customer wall freezes. SYNC-2 budget (POS pay → OSS visible <8s, documented `catalog_v15.php:72-75`) blown if connected interval misconfigured high.

### KDS-ADV3C-09 (P2) — KDS comment-vs-code SLO mismatch

Heal: base ≤ 60_000, jitter ≤ 30_000 → max poll wait = 90s = 1.5 min. Comment `config/catalog_v15.php:88`: "1 poll/min minimum" — that requires `base + jitter ≤ 60_000`, not the implementation. Either fix doc (clarify SLO is 1/90s) or clamp jitter to `floor(base/2)`.

### KDS-ADV3C-10 (P2) — Zero-jitter accepted = thundering herd

`clampJitter` (`KdsSyncService.js:478`) and `max(0, ...)` (`catalog_v15.php:92, 94, 96`) accept jitter=0. Multiple KDS stations with same `base_ms` and zero jitter sync polls → defeats hash-spread purpose, periodic spikes hit `/api/kds/sync`. Floor jitter to `≥ base/10`.

### KDS-ADV3C-11 (P3) — Long-running station never picks up config changes

`_runtimeCadenceOptions()` runs once at constructor (`KdsSyncService.js:59`). Env-flip during service hours = no effect for in-flight stations. Not introduced by heal, but the heal narrative ("protects against misconfig") implies a runtime guardrail. Doc fix.

---

## 3. P0/P1 findings (NEW)

| ID            | P  | Surface                              | File:line                                                                       |
|---------------|----|--------------------------------------|---------------------------------------------------------------------------------|
| KDS-ADV3C-01  | P0 | Admin Dashboard (every widget)       | `app/Services/DashboardService.php:68,69,101,102,140,141,184,185,271,326`       |
| KDS-ADV3C-04  | P0 | OSS stale-prune Paris→UTC bound      | `app/Services/OrderStatusScreenOrderService.php:100, 217`                       |
| KDS-ADV3C-02  | P1 | Sales Report PDF/Excel/list          | `app/Services/OrderService.php:135, 2286`                                       |
| KDS-ADV3C-03  | P1 | Cron daily quota reset / availability| `app/Console/Commands/ResetStaleDailyQuotaCommand.php:36,40` + `app/Services/Menu/AvailabilityService.php:60,109,282,290` |
| KDS-ADV3C-07  | P1 | POS catalog polling no upper cap     | `resources/js/services/PosSyncService.js:415-418` + `config/catalog_v15.php:63` |
| KDS-ADV3C-08  | P1 | OSS customer wall polling no cap     | `resources/js/services/OssSyncService.js:408-411` + `config/catalog_v15.php:71,76` |

Two declared heals individually convergent for declared scope — but declared scope was too narrow on both axes. **Not "no NEW P0" — 2 NEW P0 + 4 NEW P1.**

---

## 4. Negative space

Looked for and did NOT find:
1. **Heal regression on Wave 2b KdsSyncService**: `app/Services/KdsSyncService.php:78-80` still uses correct `Carbon::today($appTz)->setTimezone('UTC')`. ✓
2. **NF525 fiscal**: no `Carbon::today()` in `app/Services/Fiscal/*` (grep verified). ZReport / FiscalSequence unchanged. ✓
3. **BranchScope**: neither commit touches `BranchScope`. ✓
4. **Migration risk**: zero migrations in either commit. NF525 triggers untouched. ✓
5. **`Carbon::yesterday()`**: only remaining use is in a comment (`KitchenDisplaySystemOrderService.php:273`). No live binding. ✓
6. **Fail-closed OSS allowlist**: heal preserves `whereIn KIOSK/TAKEAWAY` (`OrderStatusScreenOrderService.php:188-192`). ✓
7. **Mix bundle drift**: `KdsSyncService.js` is ESM source; Mix-compiled bundle in `public/js/` must be rebuilt for cadence ceiling to take effect on kiosk/KDS Blade harness. Runtime gate, not a code finding.
8. **Float env coercion**: `(int) env(...)` truncates `4500.5` → `4500` (OK) and `"abc"` → `0` → `max(250,0)` = 250 (silent fallback). Minor.

---

## Closing verdict

Wave 2c heals are surgical and well-pinned for their **declared** scope. Wave 2c collectively is **NOT convergent**: the two bug-classes (Paris-vs-UTC bind, no upper cap on cadence config) survive in adjacent code paths the heal narrative implicitly promised to cover. Recommend Wave 2d:

- P0 KDS-ADV3C-01 (DashboardService TZ): convert 10 sites → `Carbon::today(config('app.timezone'))->setTimezone('UTC')->toDateString()` + sentinel.
- P0 KDS-ADV3C-04 (OSS stale-prune): `now()->subHours(N)->setTimezone('UTC')` at lines 100, 217 + extend `SisterServicesTzAwareTest` to assert this binding.
- P1 KDS-ADV3C-07/08: add `_clampInt(value, min, max)` to replace `_positiveInt` in PosSyncService + OssSyncService + PHP env clamps + 8 sentinel tests.
- P1 KDS-ADV3C-02/03: same TZ pattern in OrderService + cron + AvailabilityService.

If Wave 2d is deferred, document in `PROJECT_BRAIN.md` that heal scope is KDS+OSS list-paths only and admin Dashboard / Sales Report / OSS stale-prune still leak 1-2h/day. Silent acceptance = drift.

— RED-team Wave 3c, read-only, 2026-05-18.
