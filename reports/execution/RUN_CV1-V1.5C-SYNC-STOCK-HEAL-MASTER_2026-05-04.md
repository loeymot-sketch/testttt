# RUN — `CV1-V1.5C-SYNC-STOCK-HEAL-MASTER` — 2026-05-04

## Double-check before each step (per user)

- **Scope** : `plans/PLAN_CV1-V1.5C-SYNC-STOCK-HEAL-MASTER_2026-05-04.md` — R3 sentinel broadcast, R1 submit-time SSOT (TRACE → sentinel test), R2 WS reconnect menu refresh, no frozen-zone edits without gate (none opened).

---

### Step R3 — Sentinel CI `BROADCAST_DRIVER`

**Before**

- Confirmed `config/broadcasting.php` uses `env('BROADCAST_DRIVER')`; phpunit pins `log` for CI — test forces prod-like via `app()->detectEnvironment` + `Config::set` for the asserted shape.

**Implement**

- Added `tests/Feature/Config/BroadcastDriverConfiguredTest.php`.

**After (mini-audit)**

- `php artisan test tests/Feature/Config/BroadcastDriverConfiguredTest.php` — PASS (4 tests).

---

### Step R1 phase 1–3 — Submit path re-validation via SSOT

**Before**

- Trace : `PricingService::calculateOrder` → line ~109 `assertOptionsOrderable` → `ChoiceAvailabilityResolver::assertSelectionsOrderable` (`PricingService.php`).

**Implement**

- Added `tests/Feature/Order/SubmitRevalidatesChoiceAvailabilityThroughPricingTest.php` — rejects ingredient rupture on path identical to OrderService POS (`PricingRequest::forPos`).

**After (mini-audit)**

- `php artisan test tests/Feature/Order/SubmitRevalidatesChoiceAvailabilityThroughPricingTest.php` — PASS.
- **Decision** : NO patch on frozen `OrderService` / `FrontendOrderService` — contract frozen by sentinel test + existing SSOT chain.

---

### Step R2 — WebSocket reconnect → force menu refresh

**Before**

- Kiosk already subscribed `_wsService.on('connected')` for offline sync only; POS `_onWsConnected` refreshed orders polling only.

**Implement**

- `KioskAppComponent.vue` : on reconnect, `kioskMenu/fetchMenu` `{ force: true }` when `branchId` set.
- `PosComponent.vue` : on reconnect, `itemList(1, { overlay: false })`.

**After (mini-audit)**

- `npm run production` (Mix) — compiled successfully.

---

## EXECUTE_DELEGATION

`cursor-claude` (session) — no Codex complex cycle; routine test + small Vue wiring per plan.

## Remaining (R4)

- Full `php artisan test` / `npm test` if required by closeout; final master audit + `ACTIVE_CYCLE` CLOSED + memory episode (if not done in same session).

---

## R4 — Full baseline (session 2026-05-04)

- `php artisan test` — **1433 passed**, **24 skipped**.
- `npm test` (Vitest) — **1162 passed**, **2 skipped**.

**Mini-audit**

- No regressions on full suites after R3/R1/R2.

