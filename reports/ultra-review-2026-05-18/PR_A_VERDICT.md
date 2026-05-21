---
report_id: PR_A_VERDICT_ULTRA_REVIEW_2026-05-18
plan_reviewed: plans/ultra-plans-2026-05-18/PR_A_POS_CAISSE_ULTRA_PLAN_REVIEW_2026-05-18.md
reviewer: claude-opus-4-7 (ultra-review subagent, 5-perspective deep-read)
branch: v1-0-1-hardening-2026-05-17
head: a34d1f696
date: 2026-05-18
mode: read-only adversarial code-level audit
---

# PR-A POS Caisse — Ultra-Review Verdict

## §1 VERDICT

**NEEDS-REVISION** — Plan correctly identifies the V1 GO-LIVE blocker (P0-1 terminal_id UI absent) and the AR i18n parity gap (P0-3). P0-2 cash-trail claim is **mechanistically wrong** (downgrades to P2 audit-trail granularity preference, not a fiscal-drift block). Plan §0 staleness, two P1 severity drifts, and three missed adversarial findings require an amendment pass before T-1..T-10 execution. Ship after revision merge of §3 below; do not block V1 — but do not execute T-1 as drafted.

## §2 Plan findings — confirmed / disputed

| Plan ID | Claim | Status | Evidence |
|---------|------|--------|----------|
| P0-1 | `terminal_id` UI never shipped; CARD sale 422 once sim flag is off | **CONFIRMED + EXPANDED** | `PaymentComponent.vue` 0 matches for terminal_id; `PosV5TrancheRow.vue:1-214` 0 matches → BOTH single-tender (`PosOrderRequest.php:114-119`) AND split-tender (`SplitPaymentService.php:117-137`) CARD paths break. Plan correctly elevates to P0; expand scope to call out both paths in T-2. |
| P0-2 | Single-tender CASH path missing OUT line for change-due → reconcile drift Σ(change) | **DOWNGRADED to P2** | `OrderService.php:1034-1038` writes IN = `$order->total` (NOT `pos_received_amount`). `CashDrawerService.php:257-262` reconcile sums `cash_movements.signedAmount()` ONLY — never reads `pos_received_amount` (grepped entire `app/Services/Cash/` + `app/Services/Fiscal/` → 0 hits). Drawer net cash = order total = IN row → **already correct**. Real concern is NF525 audit-trail granularity (tendered + change not on cash ledger), which is a P2 forensic enhancement, not a P0 fiscal block. |
| P0-3 | AR i18n parity 0/11 on split-payment surface | **CONFIRMED** | `grep -c` on `ar.json` for the 11 keys → 0 matches; `fr.json` lines 140/190/191/662/663/664/665/667/668/1024/1026 → 11/11; `en.json` lines 140/190/191/601/602/603/604/606/607/1151/1153 → 11/11. Wave Z Z1-NEW-001 precedent applies. |
| P1-1 | Hardcoded FR strings on Toutes/Réduire toggle | **CONFIRMED** | `PosComponent.vue:268` `:title="…'Masquer les catégories non-essentielles' : 'Voir toutes les catégories'"` + `:275` `{{ showAllCategories ? 'Réduire' : 'Toutes' }}`. Tagged R-2 in memory; correct call. |
| P1-2 | Idempotency lock key drift one-bit-diff creates two orders | **DOWNGRADED to P2** | Different `X-Idempotency-Key` values MUST yield different orders by design (per-client uniqueness contract). UNIQUE constraint on `orders.idempotency_key` (catch at `OrderService.php:1075` `23000`) protects same-key races. The "one-bit-diff" framing is hypothetical, not an exploit — plan itself admits "Defense-in-depth, not a confirmed exploit." T-3 is over-scoped for V1.0.1. |
| P1-3 | N+1 on `PosCategoryController::index` | **CONFIRMED** | `PosCategoryController.php:81-105` runs `whereHas('items', …)` with nested `whereNotExists`/`orWhereExists` against `item_branch_availability`, no eager-load. Per-cashier landing call. Composite index `(item_id, branch_id, is_available)` legit. |
| P1-4 | `reorderItems` no explicit branch guard | **DOWNGRADED to P2** | `PosOrderController.php:197-226` uses implicit `Order $order` route-binding. Laravel runs `Order::findOrFail($id)` which **applies global scopes**. `Order` has `BranchScope` (`Order.php:92`). Cross-branch returns 404 (ModelNotFoundException), no data leak. Plan admits "BranchScope global protects today" — guard is future-proofing, P2 not P1. |
| P2-1 | confirmCounterPayment cash counter not sim-affected | **CONFIRMED** | `PaymentService.php:158-265` no sim flag check; only kiosk counter-collect callers, intentional separation. |
| P2-2 | Walk-in customer phone `PENDING_WALKIN` sentinel | **NOT VERIFIED** | Out of read-window scope (`WalkInCustomerResolver.php` not deep-read this pass). Plan claim plausible. |
| P2-3 | `quote` leaks raw PricingService exceptions | **CONFIRMED** | `PosController.php:209-214` `catch (Exception $e) { return … $e->getMessage() … 422 }`. Direct leak of "Composition #N not published" possible. |
| P2-4 | `normalizeCartForApi` doesn't strip `_optimistic`/`_tempId`/`_optimisticListsDelta` | **CONFIRMED** | `posCart.js:196-215` spreads `...normalizedItem` (line 201) which retains internal underscore keys (`posCart.js:372-374, 392-398` shows these are set on normalizedItem path). Noisy payload + tampering surface. |
| P2-5 | `show()` 403/404 timing-fix unified | **CONFIRMED + AGREED** | `PosOrderController.php:107-121` — Wave 5I A.1 fix, intentional security trade-off. No fix needed. |

## §3 NEW findings — plan missed

**NEW-1 P2 — Plan §0 working-tree claim is STALE.** Plan §0 line 17 states `PaymentService.php`, `SplitPaymentService.php`, `PosOrderRequest.php`, 5 test files "all modified locally, not yet committed." `git status --short` on the same files returns **empty** — those changes landed in commit `31a33cd24` per BRAIN §3:100. Process credibility risk; either re-snapshot before exec or strike the claim from §0.

**NEW-2 P0 (groups under P0-1) — Split-tender CARD path also broken.** Plan P0-1 mentions `SplitPaymentService.php:117-137` enforcement but doesn't call out that `PosV5TrancheRow.vue:1-214` has NO `terminal_id` field/selector. The atom emits `update(patch)` with only `mode|amount|tendered`, so every CARD tranche in split mode also fails the `terminalId<=0` guard. T-2 acceptance criteria must include the per-tranche selector on the row atom, not just on PaymentComponent.

**NEW-3 P1 — Non-strict `recordCashOrderMovement` silently swallows errors.** `PaymentService.php:350-361` — when `$strict=false` (kiosk counter-collect path, `PaymentService::confirmCounterPayment` line 260), any `Throwable` in `recordMovement` is caught, logged as `Log::warning`, and the order proceeds with NO cash_movement IN row. NF525 audit chain still records `order.counter_payment_confirmed` but the cash ledger drops the entry → end-of-day reconcile under-counts kiosk-counter-collect cash by exactly the silently-failed orders. Add a counter+sentinel; promote to strict mode under simulation_hardware=false.

**NEW-4 P2 — Frozen-zone drift vs `main` not surfaced.** Plan §0 line 17 claims "Frozen-zone diff vs branch HEAD = 0 in PR-A controllable surface." Correct in working-tree terms, but `git diff --stat main..HEAD -- public/js/pos-wizard.js public/css/pos-wizard.css resources/views/admin-pos-v4.blade.php` shows **`pos-wizard.js +237` / `admin-pos-v4.blade.php +165`**. BRAIN §3:141 acknowledges this via retro `LOCK_POS-A4`. Plan should explicitly reference that LOCK and confirm no further drift expected during PR-A — owner gate G2 is the wrong place for this (it's about XSS escape, not the retro accept).

**NEW-5 P2 — `PosController::quote` raw exception leak covers BOTH PricingService AND `OrderQuoteService` exceptions.** `PosController.php:209-214` final catch wraps **all** non-validation/non-Http exceptions. Plan T-8 limits scope to PricingService — should be extended to `OrderQuoteService::quote()` exceptions too (quote-token tampering, signature mismatch, etc. could leak internals).

## §4 Task acceptance-criteria critique

- **T-1** (cash-trail OUT line) — Acceptance criterion "drawer reconcile delta=0 on overpay" is already true today (reconcile doesn't read `pos_received_amount`). Reframe T-1 to "NF525 audit-trail granularity: write cash_movement metadata `tendered` + `change` columns to the ledger" and demote to P2. If kept, it must include a migration adding `tendered_amount` + `change_amount` columns to `cash_movements` (currently only `amount` + `direction`).
- **T-2** (terminal_id) — Strong; needs G1 owner gate FIRST. Acceptance must explicitly cover BOTH single-tender (PaymentComponent.vue) AND each tranche row (PosV5TrancheRow.vue). Recommend G1=B (UI + seeded default) — Option A leaves the system in permanently-sim-on territory which contradicts CLAUDE.md §8 NF525 intent.
- **T-3** (idempotency canonical key) — Over-scoped. With UNIQUE constraint + DB-level catch already in place, the lock key drift is theoretical. Demote to V1.0.2 backlog if cycle budget tight.
- **T-4** (reorderItems branch guard) — Defense-in-depth only; OK as small belt-and-suspenders. Vague acceptance "cross-branch reorder → 403" — currently it would 404 (Laravel implicit binding + BranchScope), and the test should assert the **new** 403 contract isn't accidentally bypassed by future BranchScope refactors.
- **T-5** (AR i18n) — Acceptance "11/11 keys" is enforceable; ensure RTL flip is also asserted via Vitest snapshot, not just key count.
- **T-6** (Toutes/Réduire i18n) — Strong, low-risk.
- **T-7** (PosCategoryController perf) — `<5 queries vs legacy 12+` is benchmark-dependent. Use `DB::enableQueryLog()` + assert count, not wall-clock.
- **T-8** (quote error mapping) — Extend per NEW-5 above.
- **T-9** (posCart sanitization) — Strong; ensure delete keys cascade into `pos_line_addons[]` too (currently `posCart.js:206-212` spreads addon objects via `...addon` which would also retain underscore keys if migrated).
- **T-10** (E2E cash-trail visual) — Visual capture mandate per CLAUDE.md §6; OK as written.

## §5 Frozen-zone drift report

`git diff --stat main..HEAD` filtered to PR-A scope shows:
- `public/js/pos-wizard.js` **+237 lines** (composer-aware iter12 + iter15 — known, retro `LOCK_POS-A4`)
- `resources/views/admin-pos-v4.blade.php` **+165 lines** (same retro LOCK)
- `public/css/pos-wizard.css` **0 lines** ✓
- `app/Services/Pricing/PricingService.php` (NF525 frozen, NOT in PR-A scope) **0 NEW lines** vs PR-A working window ✓
- `app/Services/Fiscal/*.php` 0 changes ✓
- `app/Domain/Order/OrderStateMachine.php` 0 changes ✓
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` 0 changes ✓
- `app/Models/Scopes/BranchScope.php` 0 changes ✓

Working tree (PR-A controllable) frozen-zone diff = **0 NEW** vs branch HEAD. Cumulative diff vs `main` is the retro-LOCK envelope, not PR-A scope. Plan §0 wording should be tightened.

## §6 Owner gates surfaced

- **G1 (terminal_id resolution path)** — Plan correct. **Recommend G1=B** (ship UI + seed default per branch). Option A leaves CARD sales permanently gated by `POS_SIMULATION_HARDWARE=true`, which contradicts CLAUDE.md §8 NF525 enforcement intent and means production sim=false will hard-block CARD. Owner must answer before T-2 starts.
- **G2 (LOCK POS wizard XSS)** — Out of PR-A scope per plan; correct to surface but not block. Independent owner action (`plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` pending countersign).
- **NEW G3 — T-1 reframing or kill**. Owner decides: keep T-1 as audit-trail granularity P2 (migrate `cash_movements` schema), or kill T-1 entirely (current behavior is NF525-acceptable, just less forensic). Default if no answer: kill T-1.

## §7 Final recommendation

**Ship after revision** — not block V1, not green-light as drafted. Required pre-execution amendments:

1. **Strike or rewrite T-1**: P0-2 mechanism is wrong. Either kill or reframe as P2 audit-trail granularity (new G3 needed).
2. **Expand T-2 scope** to cover PosV5TrancheRow.vue + PaymentComponent.vue + per-branch default terminal seeder + `config/pos.php` `card_terminal_required` flag.
3. **Demote T-3 + T-4** to V1.0.2 backlog (defense-in-depth, low ROI in V1.0.1 cycle budget).
4. **Add task T-11**: heal `recordCashOrderMovement` silent swallow (NEW-3 P1) — promote `$strict=true` under sim=false OR add per-failure audit sentinel.
5. **Tighten §0 staleness**: re-snapshot or strike the working-tree claim.
6. **Surface retro-LOCK** in §6 (NEW-4) and explicitly call out cumulative-vs-main delta as out-of-PR-A.

Estimated additional cycle budget post-revision: 30-45 min plan rewrite + G1/G3 owner round-trip. Net wall-clock unchanged (T-1 removed offsets T-11 added). PR-A remains the right vehicle to unblock V1 GO-LIVE provided G1=B is approved.

---

**Counts**: CONFIRMED 7 / DOWNGRADED 3 / NEW 5 / STALE 1. Single most important issue: **P0-1 terminal_id UI across single AND split-tender CARD paths** — V1 GO-LIVE blocker once `POS_SIMULATION_HARDWARE=false`.
