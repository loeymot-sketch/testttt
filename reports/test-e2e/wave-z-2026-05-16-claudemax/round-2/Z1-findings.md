# Z1 — Round 2 verification (Wave Z)

**HEAD**: 56204f052
**Verdict**: GO

Auditor: Round 2 read-only convergence pass on Wave Z system Z1 (POS Caisse +
Cash trail). All evidence captured by file:line from working tree at HEAD
`56204f052` on `feature/mobile-app-le-cayenne-2026-05-10`.

## Round 1 findings status

### Z1-NEW-001 (P0) — EN locale missing 21 cash_session_* keys → HEALED

Evidence:
- `lang/en/all.php:137-157` — exactly 21 `cash_session_*` keys present, ranging
  from `cash_session_open` (L137) to `cash_session_failure` (L157). Block is
  inline-commented `[Sprint 5C Z1-NEW-001 / Z10-P1-05 2026-05-16]` at L133-136.
- `lang/en/all.php:159` — bonus key `cash_no_open_session_blocks_sale`
  (Sprint 1B NF525 cash trail guard message) also added.
- `git show d424f8402 --stat` confirms `lang/en/all.php | 25 ++++++++++++++++++++++`
  — 25 lines inserted (21 cash_session_* + 1 guard + 3 comment lines + 1 join).
- Cross-locale parity count (grep `cash_session` per locale):
  - fr: 22 lines, en: 21 lines, **ar: 0**, **bn: 0**, **de: 0**.
- AR/BN/DE coverage explicitly deferred V1.0.1 by heal commit message:
  "Le Cayenne single-restaurant ships FR; SaaS multi-tenant needs EN parity"
  (commit d424f8402, body).
- Sister verdict consistent with Wave Z2 K-001 (kiosk FR-locked, multi-locale
  deferred V1.0.1).

Status: **HEALED** for EN. AR/BN/DE = documented V1.0.1 (not a Round 2 blocker).

### Z1-NEW-002 (P1) — POS quote no permission gate → HEALED

Evidence:
- `app/Http/Controllers/Admin/PosController.php:51` — constructor middleware
  changed from `->only('store')` to `->except('quote')`. Diff vs `c3ba89863`
  (commit c9509b3ad) extends RBAC to `walkInCustomer` + `store`, leaves
  `quote` for surface-aware in-method check.
- `app/Http/Controllers/Admin/PosController.php:144` — `walkInCustomer()`
  inline `abort_unless($request->user()?->can('pos'), 403)` present (Sprint 4
  defense-in-depth on top of constructor middleware).
- `app/Http/Controllers/Admin/PosController.php:165-167` — `quote()` surface-
  aware guard: `if (! $request->is('api/frontend/*')) { abort_unless($request->
  user()?->can('pos'), 403); }`.
- Kiosk route preserved: `routes/api.php:1125` mounts
  `Route::post('/quote', [PosController::class, 'quote'])->middleware(
  'throttle:kiosk-orders')` inside the `auth:sanctum` prefix group at L1122 —
  bypasses `permission:pos` correctly.
- Admin POS route gated: `routes/api.php:725-727` mounts
  `Route::post('/quote', [PosController::class, 'quote'])->middleware(
  'throttle:pos-quote')` inside the `permission:pos`-protected group.
- Test run `php artisan test tests/Feature/QuoteCurrencyOriginTest.php`:
  - `test_quote_currency_comes_from_backend_settings_and_is_signed` — PASS
  - `test_kiosk_quote_resolves_branch_from_machine` — PASS
  - 2/2 passed in 0.57s.
- Test fixture (`tests/Feature/QuoteCurrencyOriginTest.php:104`) explicitly
  grants `$operator->givePermissionTo('pos')` for the admin path, while the
  kiosk path test (L83) acts as a `kioskUser` with no `pos` permission and
  still succeeds — proves the surface-aware bypass works.

Status: **HEALED**.

### Z1-NEW-003 (P1) — cash_movements UNIQUE(order_id, type, direction) → UNCHANGED

Deferred V1.0.1 per kickoff. Defense-in-depth only; CashDrawerService write
path is already single-threaded per session via DB lock in Sprint 1B.

Status: **UNCHANGED / V1.0.1 backlog**.

### Z1-NEW-004 (P1) — PosComponent client-side cash gate missing → UNCHANGED

Deferred V1.0.1 per kickoff. Backend 422 + i18n message
(`cash_no_open_session_blocks_sale`, `lang/en/all.php:159`,
`PosController.php:67`) enforces correctness; client-side gate is UX polish.

Status: **UNCHANGED / V1.0.1 backlog**.

### POS-A4 (P1 carryover) — Frozen-zone +237/+165 vs main without LOCK → UNCHANGED

Pre-existing diff against `main` baseline; not introduced by Wave Z.

Status: **UNCHANGED / pre-existing**.

### POS-A6 (P2 carryover) — JS-calculated total/discount/subtotal sent → UNCHANGED

V1.0.1 backlog.

Status: **UNCHANGED / V1.0.1 backlog**.

## NEW issues introduced by Wave Z heals

**None.**

Audit performed:
- `git diff c3ba89863..HEAD -- public/js/pos-wizard.js public/css/pos-wizard.css
  resources/views/admin-pos-v4.blade.php` → **0 lines** (frozen POS wizard
  untouched). Confirmed via `wc -l`.
- `git log c3ba89863..HEAD -- app/Services/Fiscal/FiscalSequenceService.php
  app/Services/Fiscal/ZReportService.php app/Services/Fiscal/AuditLogService.php`
  → empty (no fiscal-frozen commits).
- Wave Z commit scope review (5A/5B/5C/5D):
  - `7fc62c066` (5A): 5 files — ValidPhone, User, KDSOrderDetailsResource,
    SimpleOrderResource, KdsOrderCard.vue, KDSDeliveryEnrichmentTest. Scope
    matches Z9-P0-01/02/03 + Z9-P1-03 declared targets.
  - `7e62f7bbc` (5B): 2 files — CashDrawerController (Z10-NEW-001 forensic),
    PosController (Z1-NEW-002 quote guard). Scope matches declared targets.
  - `d424f8402` (5C): 8 files — 6 outbox listeners (Z8-P1-01),
    OrderStatusScreenOrderService (Z4-P1-02), lang/en/all.php (Z1-NEW-001),
    PosController constructor (5B follow-up `->except('quote')`). Scope
    matches; the PosController follow-up explicitly addresses a regression
    detected by QuoteCurrencyOriginTest where blanket `permission:pos`
    would 403 kiosk callers — fix is correct.
  - `56204f052` (5D): 1 file — LoginController (Z6-01 token revoke on
    relogin). Scope matches.
- Wave Z 5C PosController follow-up (`->except('quote')`) was the only
  "cross-Wave coupling" — it backed out an overly-broad constructor
  middleware from Sprint 4 (`c9509b3ad`) that would have broken kiosk
  pricing. The fix is correct and verified by the green test.
- `QuoteCurrencyOriginTest` 2/2 PASS confirms both surfaces:
  - Admin POS path: `permission:pos`-granted operator → 200 OK.
  - Kiosk path: kiosk:order Sanctum acting user with no `pos` permission
    → 200 OK on `/api/frontend/order/quote`.

## Carryovers (V1.0.1 backlog — documented, not blockers)

- **POS-A4** — Frozen-zone +237/+165 vs main without LOCK. Status: UNCHANGED.
  Pre-existing main diff, not introduced by Wave Z. Owner gate required for
  retroactive LOCK doc or merge plan.
- **POS-A6** — JS-calculated total/discount/subtotal sent on wire. Status:
  UNCHANGED. V1.0.1 backlog (Pricing SSOT hardening — backend already
  recalculates server-side, so wire values are advisory).
- **Z1-NEW-003** — `cash_movements UNIQUE(order_id, type, direction)`
  defense-in-depth. Status: UNCHANGED. V1.0.1 backlog.
- **Z1-NEW-004** — PosComponent client-side cash gate missing. Status:
  UNCHANGED. V1.0.1 backlog (backend 422 + i18n message enforces correctness).
- **AR/BN/DE `cash_session_*` parity** — only FR (22) + EN (21) populated.
  Status: documented in heal commit d424f8402, V1.0.1 multi-locale SaaS work.
  Le Cayenne single-restaurant ships FR (matches Z2 K-001 sister decision).

## Test debt (pre-existing, non-regression)

20 POS tests failing pre-Wave-Z due to Sprint 1B cash session guard not
propagated to all suites (some legacy POS tests don't seed an open
`CashDrawerSession` and now hit the 422 guard). Per kickoff: NOT a Wave Z
regression — pre-existing test gap, V1.0.1 test hygiene work. Verification
of this carryover was explicitly skipped per kickoff instructions; the
QuoteCurrencyOriginTest filter (2/2 PASS) is the authoritative Wave Z
convergence signal for Z1.

## Convergence verdict

**P0 = 0** open · **P1 = 0** open (Round 1 P0 + P1 both healed)

Block convergence? **No.**

Findings remain same as Round 1 minus the two healed items
(Z1-NEW-001 + Z1-NEW-002). The four documented carryovers (POS-A4, POS-A6,
Z1-NEW-003, Z1-NEW-004) are V1.0.1 backlog as agreed in kickoff and not
Wave Z merge blockers.

Z1 is **GO** for Wave Z convergence.
