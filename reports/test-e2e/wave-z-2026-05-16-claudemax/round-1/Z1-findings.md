# Z1 — POS Caisse + Cash Trail (Round 1 Wave Z findings)

**Auditor**: Z1 sub-agent (RED-team read-only)
**Date**: 2026-05-16
**HEAD**: c3ba89863
**Verdict**: GO-CONDITIONAL

## Summary
Sprint 1A/1B/1B-followup heal the two P0 NF525 cash-trail gaps cleanly: `OrderService::posOrderStore` writes a `CashMovement` inside the parent DB::transaction (single-tender CASH) and `SplitPaymentService::persistTranches` writes one movement per CASH tranche; both fail-fast with `CashDrawerSessionNotOpenException` (422) when no `CashDrawerSession::STATUS_OPEN` exists. Frozen-zone diff over heal window 76d641135~1..c3ba89863 = 0 lines on pos-wizard.js / pos-wizard.css / admin-pos-v4.blade.php. New issues: **EN + AR locales are missing 21 `cash_session_*` keys** (FR-only catalog → fallback or raw-label in dialog for non-FR cashiers — P0 in i18n sense, P1 production), **POS-A3 `/pos/quote` is still ungated** (only `walkInCustomer` got `can('pos')`), **POS-A6 still open** (form.total computed in JS), and `cash_movements` lacks UNIQUE(order_id, type, direction) so worker retry without idempotency could double-write.

## Sister-verdict P0 status (verify-healed)

- **POS-A1 (P0): HEALED** — `app/Services/OrderService.php:1017-1039` adds `recordCashOrderMovement(strict=true)` call inside the DB::transaction for single-tender CASH path after `fiscal_sequence_no` allocation. Heal commit `2e3635d64` adds the block lines 1017-1039 (verified via `git show 2e3635d64 -- app/Services/OrderService.php`). Strict=true => throws `CashDrawerSessionNotOpenException` (HTTP 422) which rolls back the order + movement atomically.
- **POS-A2 (P0): HEALED** — `app/Services/Payments/SplitPaymentService.php:159-190` injects `CashDrawerService` (constructor line 39), runs pre-transaction guard `findOpenSessionForUser` if any tranche is CASH, throws `CashDrawerSessionNotOpenException` if missing (lines 176, 183). Movement write inside transaction `app/Services/Payments/SplitPaymentService.php:234-244` calls `cashDrawerService->recordMovement(strict=true)` for each CASH tranche with `amount = tranche.amount` (NOT order.total — correct per audit expectation). Verified via `git show 2e3635d64 -- app/Services/Payments/SplitPaymentService.php`.
- **POS-A3 (P1): PARTIALLY HEALED** — `walkInCustomer` (`app/Http/Controllers/Admin/PosController.php:136`) now `abort_unless($request->user()?->can('pos'), 403)`. **HOWEVER** `quote` method (`app/Http/Controllers/Admin/PosController.php:149-190`) STILL lacks `permission:pos` middleware AND in-method `abort_unless`. Route registration `routes/api.php:725` `Route::post('/quote', [PosController::class, 'quote'])->middleware('throttle:pos-quote')` — only throttle, no permission. Constructor `app/Http/Controllers/Admin/PosController.php:43` `$this->middleware(['permission:pos'])->only('store')` still gates `store` only. Any authenticated admin user can hit `/api/admin/pos/quote` regardless of POS role → pricing-logic exposure + side effects via `quote_token` consume.
- **POS-A4 (P1): NOT HEALED** — Frozen-zone diff vs main on `public/js/pos-wizard.js` = +237 / -21 lines, `resources/views/admin-pos-v4.blade.php` = +165 lines (verified via `git diff main..c3ba89863 --stat -- public/js/pos-wizard.js resources/views/admin-pos-v4.blade.php`). No `LOCK_*.md` doc exists for either file (verified via `find . -maxdepth 4 -name "LOCK_*pos-wizard*" -o -name "LOCK_*POS_WIZARD*"` returns empty). Pre-existing drift from commits `91a1e1b2c`, `5218168ef`, `9730b18e7`, `53f1ea45c`, `6a975dfff`, `87011d916`, `3dbd6bfa3` (per `git log main..c3ba89863 --oneline -- public/js/pos-wizard.js`). HEAL WINDOW IS CLEAN: `git diff 76d641135~1..c3ba89863 --stat -- public/js/pos-wizard.js public/css/pos-wizard.css resources/views/admin-pos-v4.blade.php` = 0 files / 0 lines.
- **POS-A5 (P1): HEALED** — `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue` exists (verified via ls); Vuex module `resources/js/services/CashDrawerService.js` lines 55,71,100,109,131 hits all 5 backend endpoints (`current`, `open`, `close`, `reconcile`, `movements`); wired into `resources/js/components/admin/pos/PosComponent.vue:1280` (`isSessionActive` getter), `:1712-1719` (autoload in `defaultAccess`), `:1751` (re-fetch). Vitest spec `tests/js/PosCashDrawerSessionDialog.spec.js` exists.
- **POS-A6 (P2): NOT HEALED** — `resources/js/components/admin/pos/PosComponent.vue:2826` `this.checkoutProps.form.subtotal = this.subtotal;` and `:2837` `this.checkoutProps.form.total = Number(this.grandTotal).toFixed(...)` still send JS-calculated total/subtotal to backend (POS-A6 was tagged P2 and not in heal scope per kickoff — flagged here for visibility).

## P0 NEW (introduced or missed)

- **Z1-NEW-001 (P0 — i18n catalog gap)**: EN and AR locales are MISSING all 21 `cash_session_*` keys that exist in FR. Verified:
  - `lang/fr/all.php:130-150` defines 21 keys (`cash_session_open`, `cash_session_close`, `cash_session_opening_amount`, `cash_session_closing_amount`, `cash_session_variance`, `cash_session_variance_reason`, `cash_session_movements`, `cash_session_active`, `cash_session_no_session`, `cash_session_manager_approval_required`, `cash_session_expected_amount`, `cash_session_opened_at`, `cash_session_movements_count`, `cash_session_no_movements`, `cash_session_confirm_close`, `cash_session_view_movements`, `cash_session_back`, `cash_session_header_btn`, `cash_session_dialog_title`, `cash_session_required_reason`, `cash_session_failure`).
  - `lang/en/all.php`: `grep -c cash_session_` returns 0; only `cash_no_open_session_blocks_sale` (line 134) exists.
  - `resources/js/languages/en.json`: `grep -c cash_session_` returns 0 (only `cash_drawer_open` line 413).
  - `resources/js/languages/ar.json`: `grep -c cash_session_` returns 0 (only `cash_drawer_open` line 274).
  - `PosCashDrawerSessionDialog.vue` lines 8, 90, 203, 225, 233 reference `label.cash_session_dialog_title`, `label.cash_session_opening_amount`, `label.cash_session_closing_amount`, `label.cash_session_variance_reason` via `$t()` — for EN/AR users the dialog will display raw key strings or fallback-FR per i18n config. Direct customer-facing in admin POS: non-FR cashier sees raw `label.cash_session_dialog_title` in dialog header. NF525 sensitive workflow (counted-amount, variance reason) → unacceptable in EN production locales.

## P1 NEW

- **Z1-NEW-002 (P1)**: POS-A3 carryover (see above) — `app/Http/Controllers/Admin/PosController.php:149` `quote()` has no `permission:pos` gate. Risk: pricing-engine probe + idempotent quote_token consumption by authenticated-but-non-POS users. Heal: add `abort_unless($request->user()?->can('pos'), 403);` at line 168 (after `normalizePosRuntimePayload`).
- **Z1-NEW-003 (P1)**: `database/migrations/2026_05_08_140100_create_cash_movements_table.php` lacks a UNIQUE constraint on `(order_id, type, direction)`. If the idempotency middleware on `/api/admin/pos/` (`routes/api.php:728`) is bypassed (e.g. internal sync flow, or middleware cache miss after Redis flap), `posOrderStore` could be replayed and write a 2nd CashMovement for the same order → Z-report variance inflated by 2× the cash component. The OrderService transaction does not currently check `CashMovement::where('order_id', ...)->exists()` before write. Idempotency middleware is best-effort, DB constraint is the only guaranteed defense.
- **Z1-NEW-004 (P1 — frontend wiring)**: `resources/js/components/admin/pos/PosComponent.vue:1280` reads `cashDrawer/isOpen` getter to display "ready" tone on the Caisse button, but there's NO client-side gate preventing the cashier from clicking the cash-pay button when the getter is false. The backend 422 path is the only enforcement. Owner-friendliness: a UI-side guard would surface the error before the network round-trip and avoid abandoning a built cart.

## P2 NEW

- **Z1-NEW-005 (P2 — a11y)**: `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue` has `role="dialog"`, `aria-modal="true"`, `aria-label` on backdrop (lines 6-8), backdrop close + role="alert" on errors, BUT no `@keydown.esc` handler on the dialog wrapper and no `tabindex` focus-trap. Only CSS `:focus` (line 709-710). WCAG 2.1 SC 2.1.2 (No Keyboard Trap) + SC 2.1.1 (Keyboard) gap: an arrow-key-only operator on a sticky-Caps keyboard cannot dismiss the modal without mouse.
- **Z1-NEW-006 (P2 — POS-A6 carryover)**: `resources/js/components/admin/pos/PosComponent.vue:2826-2837` still sends `form.subtotal` + `form.total` computed JS-side. SSOT violation per CLAUDE.md §8 ("Frontend envoie `item_id, quantity, option_ids` UNIQUEMENT"). Backend SSOT defense via `OrderQuoteService` and `PricingService::calculateOrder` makes this benign in practice — but the contract drift is real.

## P3 NEW

- **Z1-NEW-007 (P3 — test seam)**: `tests/Feature/Pos/PosCashTrailTest.php` covers 6 paths (verified via `grep -c "public function test"`). Missing edge cases:
  - retry attack: 2nd POST with same idempotency-key + open session — expect 0 duplicate `cash_movement`.
  - manager override: cashier without `cash.reconcile.variance.override` permission cannot close a session with |variance|>threshold (logic enforced in `CashDrawerService` per `CashVarianceGateTest.php` but not cross-tested with POS sale flow).
  - SplitPayment 3-tranche CASH+CASH+CARD — only 2-tranche is covered.

## Frozen-zone diff status

- `public/js/pos-wizard.js`: **0 lines** over heal window `76d641135~1..c3ba89863` (verified). vs main: +237 / -21 (pre-existing drift, see POS-A4).
- `resources/views/admin-pos-v4.blade.php`: **0 lines** over heal window. vs main: +165 (pre-existing).
- `public/css/pos-wizard.css`: **0 lines** over heal window. vs main: 0.

## Evidence pointers

- `git show 2e3635d64 -- app/Services/OrderService.php` lines 1017-1039: `Sprint 1B 2026-05-16` block added.
- `app/Services/OrderService.php:1010-1041` post-heal: `splitActive` gating + `recordCashOrderMovement(strict=true)` call.
- `app/Services/Payments/SplitPaymentService.php:159-244`: cash session guard + per-tranche `recordMovement(strict=true)`.
- `app/Http/Controllers/Admin/PosController.php:43`: middleware('permission:pos')->only('store') — `walkInCustomer/quote` are NOT covered.
- `app/Http/Controllers/Admin/PosController.php:54-61, 70-128`: `assertCashDrawerSessionOpenIfCashInvolved` defense-in-depth guard.
- `app/Http/Controllers/Admin/PosController.php:136`: `walkInCustomer` `abort_unless(can('pos'))` healed.
- `app/Http/Controllers/Admin/PosController.php:149-190`: `quote` method — NO permission gate.
- `app/Services/Cash/CashDrawerService.php:326-410`: `recordMovement` impl + strict mode behavior.
- `app/Services/Cash/CashDrawerService.php:415-422`: `findOpenSessionForUser` helper.
- `lang/fr/all.php:130-150` (21 keys) vs `lang/en/all.php:0` cash_session keys vs `resources/js/languages/en.json:0`, `ar.json:0`.
- `routes/api.php:269` `admin` prefix authenticated via auth:sanctum; `routes/api.php:725` `/quote` throttle only.
- `routes/api.php:728` POS store has `['throttle:pos-order-create', 'idempotency']` — idempotency middleware present but not enforced at DB level.
- `tests/Feature/Pos/PosCashTrailTest.php`: 6 tests, 34 assertions per heal commit message.
- `database/migrations/2026_05_08_140100_create_cash_movements_table.php`: no UNIQUE on (order_id, type, direction).
- `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue`: a11y attrs OK lines 6-8, 23, 35, 60, 90, 175, 203, 225, 233 but no focus-trap / @keydown.esc.

## Convergence recommendation

P0+P1 count: **1 P0 (Z1-NEW-001 EN/AR i18n) + 3 P1 (Z1-NEW-002 quote authz, Z1-NEW-003 cash_movements unique, Z1-NEW-004 client gate)**.

**Block convergence? Yes.** Z1-NEW-001 is a blocker for any non-FR production install — the dialog is dead UX in EN. Z1-NEW-002 carries the original POS-A3 P1 forward as half-healed. The two NF525-grade P0s (POS-A1 + POS-A2) ARE properly healed and are the core of the Sprint 1B success.

Recommend heal-light: (a) backfill `cash_session_*` keys in `lang/en/all.php` + `resources/js/languages/en.json` (+ AR best-effort), (b) add `abort_unless(can('pos'))` to `PosController::quote`, (c) optional add unique index migration on cash_movements + a `firstOrCreate` lookup before write. Then re-audit Z1 round 2.
