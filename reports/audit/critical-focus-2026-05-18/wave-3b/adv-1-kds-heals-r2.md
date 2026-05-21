# Adversarial RED — KDS Heals Wave 2b — Wave 3b dispute

**Branch:** `v1-0-1-hardening-2026-05-17`
**Commits under review:**
- `148dbebce` — KdsSyncService TZ-aware boundaries (P0 KDS-ADV3-01 heal)
- `a1dd60f56` — KDS polling cadence floor clamp (P1 KDS-RED-09 heal)

**Mode:** read-only, hostile, file:line strict, NO cloud, NO frozen-zone suggestions.

**Verdict overall:** Both commits are **technically correct in their scope** but **incomplete in blast radius** — one P0 escapes because the heal patched 1 of 3 sister code paths sharing the identical bug. Cadence clamp lacks an upper bound (silent-blind failure mode worse than DoS). Sentinels are shape-pinned, not behavior-verified. **Do NOT revert**; **DO open a follow-up wave** to close the residual surface before Wave 4 declares KDS green.

---

## Heal 2b-1 — TZ-aware boundaries (`148dbebce`)

**Local verdict:** GREEN within `KdsSyncService::sync`. The patched bindings (`KdsSyncService.php:78-91`) correctly convert Paris-local day bounds to UTC literals before MySQL binding. The bug as scoped is healed.

**Empirical probes performed (read-only):**
- DST end 2025-10-26: `Carbon::today('Europe/Paris')->setTimezone('UTC')` returns `2025-10-25 22:00:00 UTC`; `endOfDay->UTC = 2025-10-26 22:59:59 UTC`; `tomorrow->UTC = 2025-10-26 23:00:00 UTC`. Window width **= 25h** at fall-back. **1-second gap** between `parisTodayEndUtc` (22:59:59) and `parisTomorrowStartUtc` (23:00:00) — orders inserted in that single UTC second will fall in BOTH branches of the `where` (standard `whereBetween` AND advance `< tomorrow`). Harmless because the WHERE is an OR — but worth pinning.
- DST start 2025-03-30: window width **= 23h** — silently shorter active window. No regression vs pre-heal, but undocumented in code comment.
- Carbon mutability: `Carbon::today()->setTimezone('UTC')` mutates the receiver AND returns the same instance (verified via `===` comparison). Heal uses **three independent `Carbon::today()/tomorrow()` calls** so no aliasing bug — fresh instances confirmed via `spl_object_hash`. **Defensive but accidental** — a future contributor that DRYs into `$today = Carbon::today($appTz)` then reuses `$today->setTimezone('UTC')` and `$today->endOfDay()` would silently corrupt the second value. **No code comment warns about this.**
- `$since` flow (line 53): JS sends `new Date().toISOString()` → always UTC `Z` → `DateTimeImmutable` preserves UTC → `format('Y-m-d H:i:s')` strips TZ but keeps UTC numerals → MySQL UTC session binds UTC literal vs UTC-stored TIMESTAMP → CORRECT. No bug here.

### KDS-ADV3B-01 (**P0 — sister services unpatched, IDENTICAL bug, IDENTICAL blast radius**)

The heal comment at `KdsSyncService.php:62` itself cites `KitchenDisplaySystemOrderService::list:91-102` as the sister fix pattern, yet:

- `app/Services/KitchenDisplaySystemOrderService.php:94` — `whereBetween('order_datetime', [Carbon::today(), Carbon::today()->endOfDay()])` **UNPATCHED**.
- `app/Services/KitchenDisplaySystemOrderService.php:100` — `where('order_datetime', '<', Carbon::tomorrow())` **UNPATCHED**.
- `app/Services/KitchenDisplaySystemOrderService.php:260, 263` — same pattern in `orderItems()` board endpoint, **UNPATCHED**.
- `app/Services/OrderStatusScreenOrderService.php:68, 73, 179, 182` — customer-facing OSS surface, **same pattern, UNPATCHED**.

**The KDS UI calls `/list` for the full hydration and `/sync` only for deltas.** Healing the delta endpoint while the hydration endpoint still drops nightly Paris [00:00–02:00] orders means the bug is observable at every hard refresh of `/kds`. The OSS surface drops the same window to customers. **Heal scope mismatch with claimed P0 production-breaking finding.**

### KDS-ADV3B-02 (P1 — sentinel cannot reproduce the bug)

`tests/Feature/Kds/KdsSyncTzAwareTest.php` runs on `DB_CONNECTION=sqlite` `:memory:` (per `phpunit.xml:39`). SQLite has no session TZ concept — the bug would NOT manifest behaviorally on SQLite regardless of binding shape. The test pins compiled SQL **bindings** (not behavior) via `DB::listen` — acceptable approximation but **never executed against MySQL in CI**. The test file docblock acknowledges this but the project has no MySQL CI matrix declared. Sentinel = shape-lock only.

### KDS-ADV3B-03 (P2 — DST coverage missing)

Test pinned to `2026-01-15 12:00:00 UTC` (Paris winter, UTC+1, no DST event). No assertion for:
- DST start (`2025-03-30`) — 23h window
- DST end (`2025-10-26`) — 25h window + 1-sec gap probed above
- DST transition day where `Carbon::today('Europe/Paris')->endOfDay()` may resolve through the `02:00→03:00` (spring) / `03:00→02:00` (autumn) jump.

Adding three date-pinned dataProvider rows would close this for negligible LOC.

---

## Heal 2b-4 — Polling cadence floor clamp (`a1dd60f56`)

**Local verdict:** GREEN as floor-only protection. PHP `config/catalog_v15.php:83-95` clamps `max(250, ...)` for bases and `max(0, ...)` for jitters. JS `resources/js/services/KdsSyncService.js:20-23, 461-470` mirrors via `CADENCE_FLOOR_MS = 250` constant + `clampBase`/`clampJitter` helpers. Defaults preserved.

**Empirical probes performed:**
- `clampBase('abc', 10000)` → 10000 (fallback path) ✓
- `clampBase('', 10000)` → 10000 ✓
- `clampBase(null, 10000)` → 10000 ✓
- `clampBase(999999999, 5000)` → **999999999** ⚠ — see KDS-ADV3B-04.
- Negative jitter → 0 ✓ — verified in `tests/js/kdsCadenceFloor.spec.js:97-107`.

### KDS-ADV3B-04 (**P1 — silent-blind failure mode, no MAX cap**)

PHP `config/catalog_v15.php:86`: `'disconnected_base_ms' => max(250, (int) env('FK_CATALOG_KDS_DISCONNECTED_BASE_MS', 10_000))`.

Owner misconfig `FK_CATALOG_KDS_DISCONNECTED_BASE_MS=999999999` → 999_999_999 ms = ~11.5 days between polls. KDS **silently goes blind** during WS disconnect — no DoS, no error, no alert. **Worse failure mode than the DoS the clamp was designed to prevent**, because DoS is visible (server cratering) while silent blindness is invisible (chef sees stale orders, customer waits, root cause hidden in env).

Same issue applies to all 3 base cadences in `config/catalog_v15.php:83,85,86` and 3 in JS `clampBase` (`resources/js/services/KdsSyncService.js:461-465`). Trivial fix: `min(60_000, max(250, ...))` PHP-side + `Math.min(60000, candidate)` JS-side.

### KDS-ADV3B-05 (P2 — integration test absent between PHP clamp and JS clamp)

The Blade wiring at `resources/views/master.blade.php:161-168` renders `@json((int) config('catalog_v15.kds_fallback_polling.high_activity_base_ms', 3000))`. PHP-side clamp test (`tests/Feature/Config/CatalogKdsCadenceFloorTest.php`) verifies the config layer. JS-side clamp test (`tests/js/kdsCadenceFloor.spec.js`) verifies the runtime layer. **Neither test verifies the wire.** A regression where Blade was rewritten to bypass `config()` (e.g. read directly from `env()`) would defeat the clamp silently. `tests/js/runtimeSyncFlagsWiring.spec.js:15` greps for the literal token `'kdsFallbackPolling: {'` — a structural smoke test, NOT a value-clamp test. No end-to-end integration assertion that `FK_CATALOG_KDS_DISCONNECTED_BASE_MS=10` reaches the JS `KdsSyncService` instance as `250`.

### KDS-ADV3B-06 (P3 — thundering-herd not addressed by 0-floor jitter)

`config/catalog_v15.php:84,87,89,91,93,95`: jitters clamped to `max(0, ...)`. **`0` is a legal value** — owner can intentionally set `FK_CATALOG_KDS_HIGH_ACTIVITY_JITTER_MS=0` and remove the de-synchronization protection cited at `KdsSyncService.js:244-250`. The PHP test `test_negative_jitter_misconfig_is_clamped_to_zero` (lines 110-118) confirms 0 is preserved, but no test asserts a **minimum positive jitter** (e.g. 50ms floor). Out of scope for KDS-RED-09 strictly but inherits the same misconfig-vector category the clamp was supposed to neutralize.

---

## Wave 3b P0 / P1 summary (new findings only)

| ID | Sev | File:Line | Title |
|----|-----|-----------|-------|
| KDS-ADV3B-01 | **P0** | `KitchenDisplaySystemOrderService.php:94, 100, 260, 263` + `OrderStatusScreenOrderService.php:68, 73, 179, 182` | Sister services unpatched — same Paris-vs-UTC bug, larger blast radius (UI hydration + OSS customer) |
| KDS-ADV3B-04 | **P1** | `config/catalog_v15.php:83,85,86` + `KdsSyncService.js:461-465` | No upper cap on cadence — silent-blind misconfig possible |
| KDS-ADV3B-02 | P1 | `tests/Feature/Kds/KdsSyncTzAwareTest.php` + `phpunit.xml:39` | Sentinel shape-pinned only; SQLite cannot reproduce the MySQL TZ bug |
| KDS-ADV3B-05 | P2 | `master.blade.php:161-168` | No PHP→JS integration test verifies `config()→@json()→window.foodkingConfig` wire delivers clamped values |
| KDS-ADV3B-03 | P2 | `KdsSyncTzAwareTest.php` | Only winter date pinned; DST start/end not asserted |
| KDS-ADV3B-06 | P3 | `config/catalog_v15.php:84-95` | Zero-floor jitter permits owner to disable thundering-herd protection |

---

## Negative space (declared, NOT probed)

- **NF525 fiscal services** (`ZReportService`, `FiscalSequenceService`) — likely carry the same Paris-vs-UTC pattern for daily-close boundaries. **Frozen zone — NOT investigated, NOT suggested.** Flag only for follow-up Wave under owner gate.
- **PHPUnit test was READ, not EXECUTED.** No empirical confirmation that `KdsSyncTzAwareTest` runs green on the current branch — only that the assertions look sound on inspection.
- **MySQL `EXPLAIN`** of the post-heal query was not run. `idx_orders_datetime` plan likely identical for UTC vs Paris-local literals (same column, same operator), but unverified.
- **Test directory casing collision** noted out-of-band: `tests/Feature/Kds/` AND `tests/Feature/KDS/` coexist (both contain `BackfillAllergensSnapshotTest.php` with mismatched namespaces `Tests\Feature\Kds` vs `Tests\Feature\KDS`). **Pre-existing**, not introduced by `148dbebce`. macOS HFS+ case-insensitive masks; Linux CI may double-discover or PSR-4-fail. The new sentinel is in `Kds/` (lowercase) — same risk vector. Out of Wave 3b scope but cited for completeness.
- **No probe of WS-driven cadence transitions** — Wave 3b focused on misconfig vectors only; the high-activity/degraded/disconnected state-machine in `_baseCadence` (`KdsSyncService.js:285-308`) was not adversarially attacked here.
- **`_cadenceOptions` snapshot at constructor (`KdsSyncService.js:55`)** — JS reads runtime config ONCE at service instantiation. If `window.foodkingConfig.kdsFallbackPolling` is mutated post-construction (dev console / unit test isolation bug), the service ignores it. Not a security bug; flagged as fragility.

---

**Final RED-team posture:** Wave 2b heals are **technically sound within their declared scope** — accept them; **but Wave 4 cannot declare KDS green** until KDS-ADV3B-01 (sister services) is closed by a follow-up surgical wave. KDS-ADV3B-04 (max cap) should be folded into the same follow-up. KDS-ADV3B-02/03/05/06 are sentinel debt — acceptable to defer to V1.0.2 backlog with explicit BRAIN entry.
