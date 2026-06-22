---
plan_id: PR_A_POS_CAISSE_ULTRA_PLAN_REVIEW_2026-05-18
system: POS Caisse
mode: read-only ultra-review + ultra-plan
author: claude-opus-4-7 (subagent)
date: 2026-05-18
branch: v1-0-1-hardening-2026-05-17
head: a34d1f696786ac701d25586561a7121de1080d84
target_pr: PR-A POS Caisse hardening
status: draft (advisor sign-off pending)
---

# PR-A — POS Caisse Ultra-Review + Ultra-Plan

## §0 Préambule

Working tree dirty (notable: `app/Services/PaymentService.php`, `app/Services/Payments/SplitPaymentService.php`, `app/Http/Requests/PosOrderRequest.php`, `tests/Feature/Pos/PosCashTrailTest.php`, `tests/Feature/Pos/SplitPaymentEndToEndTest.php`, `tests/Feature/Pos/SplitPaymentSentinelTest.php`, `tests/Unit/Services/Payment/SplitPaymentServiceTest.php`, `tests/Feature/Pos/TerminalIdWireInTest.php` — all modified locally, not yet committed). Branch `v1-0-1-hardening-2026-05-17`, HEAD `a34d1f696`. NF525 chain bit-identical (`count=26`, `last_hash=ca4ac1fdc208dae1`). Frozen-zone diff vs branch HEAD = 0 in PR-A controllable surface.

Scope of this plan = **PR-A only**. Out-of-scope sister PRs (PR-B Kiosk, PR-C KDS, PR-D OSS) tracked separately. Advisor sign-off REQUIRED on §3 task ordering before any task is started — NF525 sequencing risk.

## §1 Scope

**Backend (8 files, ~2 175 LOC):** `PosController.php` (236), `PosCategoryController.php` (171), `PosOrderController.php` (304), `PosOrderRequest.php` (243), `WalkInCustomerResolver.php` (58), `PaymentService.php` (617), `SplitPaymentService.php` (305), `OrderService.php` (POS path lines 198 + 563-1062), `config/pos.php` (62).

**Frontend (10 files, ~7 800 LOC):** `PosComponent.vue` (4 020), `PaymentComponent.vue` (1 364), `ItemComponent.vue` (1 753), `PosOrdersTrackerComponent.vue` (1 533), `ReceiptComponent.vue` (654), `ParkedOrdersComponent.vue` (537), `v5/*.vue` (9 files), store modules `posOrder.js`/`posCart.js`/`posCategory.js`/`posParked.js`.

**Frozen-zone (audit-only, NEVER touch):** `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`. LOCK `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` pending owner countersign (surfaced §5).

**Existing tests:** `tests/Feature/Pos/` (11 classes verified via `ls`) + `Traits/SeedsOpenCashDrawerSession.php` + e2e `02-pos-cash.spec.js`, `05-pos-card.spec.js`.

## §2 Ultra-Review — Findings

### P0 (block merge — 3)

**P0-1 — `terminal_id` UI never shipped (V1 GO-LIVE blocker).** `PosOrderRequest.php:114-119` makes `terminal_id` `required_if:pos_payment_method,CARD`; `SplitPaymentService.php:117-137` mirrors it for every CARD tranche. `PaymentComponent.vue` carries **zero** terminal selector (grep returns 0 matches). Result: every CARD sale fails with `422 terminal_id field is required` once owner sets `POS_SIMULATION_HARDWARE=false`. BRAIN §3:144 labels this "Stage B deferred V1.0.1.x" but the rule shipped without the deferral env-default.

**P0-2 — Single-tender CASH path: no `cash_movements` OUT line for change due.** `OrderService.php:1032-1039` writes one IN movement `amount=$order->total`. Client `pos_received_amount` (overpay) is persisted on `orders` (line 888-895) but no symmetric OUT row is created. Drawer reconcile at end-of-day therefore inflates expected-cash by Σ(change-due). Split path is correct (`SplitPaymentService.php:277-287` writes per-tranche IN). NF525 risk = Z-report systematically off by cumulative change-due delta.

**P0-3 — AR i18n parity 0/11 on split-payment surface.** `PaymentComponent.vue:152, 199, 217, 230, 250, 261, 273, 869` reference 11 keys (`label.split_summary`, `split_among_n`, `split_tranches`, `total_covered`, `remaining_due`, `auto_balance`, `auto_balance_help`, `add_tranche`, `pos.split_empty_hint`, `pos.split_not_balanced`, `pos.cash_received_auto_bumped`). `fr.json`+`en.json` declare 11/11; `ar.json` 0/11 (verified via `grep -c`). Under AR locale, every label collapses to its inline FR fallback → mixed-script UI failing RTL flip + Wave Z Z1-NEW-001 precedent.

### P1 (close in PR-A — 4)

**P1-1 — Hardcoded FR strings on PosComponent toggle.** `PosComponent.vue:268, 275` ship literal `'Masquer les catégories non-essentielles' : 'Voir toutes les catégories'` (title) and `'Réduire' : 'Toutes'` (label). Tagged R-2 P2 in `project_pos_first_page_oss_filter_2026-05-18.md`. WCAG 3.1.1 + i18n parity violation.

**P1-2 — Idempotency lock key derivation drifts from existing-order lookup canonical form.** `OrderService.php:587-596` builds lock key from `sha1($branchId . '|' . $idempotencyKey)` but `findExistingOrderForIdempotencyRecovery` (called line 592) is the actual SSOT. A near-miss double-click with one-bit-diff on `X-Idempotency-Key` passes the lock (different SHA-1 → different lock key) and creates two orders. Defense-in-depth, not a confirmed exploit.

**P1-3 — N+1 + missing index on `PosCategoryController::index`.** Lines 81-105 run `whereHas('items', …)` + nested `whereNotExists`/`orWhereExists` against `item_branch_availability`, no eager-load, no select projection. Per cashier landing call: O(categories × items × 2 sub-queries). Need composite index `(item_id, branch_id, is_available)` + `select` projection on `ItemCategory::with('media')`.

**P1-4 — `PosOrderController::reorderItems` lacks explicit branch-isolation guard.** Lines 197-226 accept `Order $order` via route-binding. BranchScope global protects today, but no explicit `abort_unless($authBranch === $order->branch_id)` (cf. `show:117-121`, `refundWithCounterEntry:56-61`). Future BranchScope bypass mirrors POS-9.1.2 — leaks `composition_snapshot` of foreign-branch orders.

### P2 (defer V1.0.2 — 5, summarized)

P2-1 `PaymentService::confirmCounterPayment` cash-counter mode unaffected by sim flag (intentional, document). P2-2 `WalkInCustomerResolver.php:31` `phone='PENDING_WALKIN'` sentinel needs pre-migration backfill check on existing walk-in user. P2-3 `PosController::quote:209-214` leaks raw `PricingService` exception messages (`composition profile #N not published`) — wrap in `ValidationException::withMessages(['composition' => trans(...)])`. P2-4 `posCart.js normalizeCartForApi:196-214` does not strip `_optimistic`/`_tempId`/`_optimisticListsDelta` keys before POST → noisy payload + future tampering surface. P2-5 `PosOrderController::show:113-116` unified 403 lost ModelNotFound diagnostic — acceptable security trade-off, no fix.

### Frozen-zone audit (information-only, NO touch in PR-A)

`public/js/pos-wizard.js` referenced by `feedback_wizard_popup_pos_protected.md` is the Vanilla JS composer. PR-A backend ingests its payload (`viande`, `crudite`, `sauce`, `sauce_supp` attribute groups per `project_pos_payment_fix_2026-05-18.md`). No drift detected — wizard payload shape stable, backend `assertComposerSelectionsBelongToPublishedProfile` is the SSOT enforcer (frozen `PricingService`). LOCK XSS escape plan `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` remains pending owner countersign — surface in §5 below.

## §3 Ultra-Plan — Tasks (ordered by risk)

> **Ordering principle (CLAUDE.md §10 risk register):** NF525/cash-trail correctness → multi-tenant security → V1 GO-LIVE unblockers → i18n parity → perf polish.

| # | Task | Anchor | Test | Acceptance |
|---|------|--------|------|------------|
| **T-1** | NF525 cash-trail: write change-due movement direction=OUT in same DB tx as the order-payment IN on single-tender CASH. Mirror split path. | `OrderService.php:1032-1039` + `PaymentService.php:289-362` (new `$changeAmount` param) | `PosCashTrailTest.php` ADD `cash_trail_records_change_movement_on_overpay()` | filter=PosCashTrail green; drawer reconcile delta=0 on overpay |
| **T-2** | `terminal_id` rule downgrade to `nullable` when `simulation_hardware=true` OR ship "Sans TPE" default terminal seeder + auto-pick UI (owner-gate G1) | `PosOrderRequest.php:114-119`, `SplitPaymentService.php:117-137`, `PaymentComponent.vue` (if G1=B) | `TerminalIdWireInTest.php` ADD `simulation_skips_terminal_required()` | CARD sale 200 under sim ON; under sim OFF either UI selector OR seeded default |
| **T-3** | Idempotency lock key canonical from `(branch_id, user_id, payload_hash)` not raw header SHA-1 | `OrderService.php:587-596` | NEW `PosIdempotencyCanonicalKeyTest.php` 3 cases | 10 concurrent identical posts → 1 order, 9 replays |
| **T-4** | Explicit branch guard on `reorderItems` | `PosOrderController.php:197-226` | NEW `PosReorderBranchIsolationTest.php` 3 cases | cross-branch reorder → 403 |
| **T-5** | AR i18n parity — add 11 split-payment keys to `ar.json` (mirror kiosk.confirmation 2026-05-08 precedent) | `resources/js/languages/ar.json` | NEW `tests/Unit/Languages/SplitPaymentI18nParityTest.php` | 11/11 keys in fr/en/ar; Vitest parity green |
| **T-6** | Wire i18n on "Toutes/Réduire" toggle (closes R-2) | `PosComponent.vue:268, 275` | NEW `tests/unit/posComponent.toggleI18n.test.js` | 0 raw FR under AR; `label.all_categories` + `label.collapse_categories` 3-lang |
| **T-7** | `PosCategoryController::index` perf — eager-load + composite index migration `(item_id, branch_id, is_available)` | `PosCategoryController.php:81-105` + NEW migration | NEW `PosCategoryIndexPerformanceTest.php` (DB::enableQueryLog) | <5 queries vs legacy 12+ |
| **T-8** | `PosController::quote` error mapping — wrap PricingService exceptions in `ValidationException::withMessages` | `PosController.php:209-214` | extend `QuoteBindingTest.php` `quote_error_message_is_safe()` | no raw `profile #N` leak |
| **T-9** | `posCart.js normalizeCartForApi` strips `_*`-prefixed keys | `posCart.js:196-214` | extend `posCart.normalizeForApi.test.js` `strips_internal_underscore_keys()` | network payload contains no `_*` keys |
| **T-10** | E2E cash-trail gate — extend cash-spec with reconcile verification for overpay scenario | `tests/e2e/02-pos-cash.spec.js` (extend) | Playwright + visual capture `/tmp/foodking-pra-cash-trail.png` | E2E green + visual analyzed (no raw labels, no broken layout) |

## §4 PR boundaries

**Suggested commit message:**

```
feat(pos-pr-a): NF525 cash-trail + terminal_id v1 gate + branch-isolation hardening

- T-1 cash_movements OUT line on overpay (NF525 reconcile correctness)
- T-2 terminal_id required_if relaxed when POS_SIMULATION_HARDWARE=true [G1=A] OR UI selector + seeded default [G1=B]
- T-3 canonical idempotency lock key per (branch, user, payload_hash)
- T-4 explicit branch guard on PosOrderController::reorderItems
- T-5 AR i18n parity 11 split-payment keys
- T-6 PosComponent Toutes/Réduire i18n wire-in (closes R-2)
- T-7 PosCategoryController perf + composite index migration
- T-8 PosController::quote error message safety
- T-9 posCart normalizeForApi strips _* keys
- T-10 e2e cash-trail visual gate

Frozen-zone diff = 0 (pos-wizard.js, pos-wizard.css, admin-pos-v4.blade.php untouched).
NF525 chain unchanged (last_hash=ca4ac1fdc208dae1).
```

**Commit grouping (4 commits):** (a) backend NF525/security (T-1, T-3, T-4, T-7, T-8); (b) backend terminal_id resolution (T-2, owner-gate dependent); (c) i18n + frontend polish (T-5, T-6, T-9); (d) e2e + verify (T-10).

## §5 Owner gates

**G1 — `terminal_id` resolution path (T-2).** WHO=owner ; WHAT=choose between (A) downgrade rule to `nullable` + log every CARD-sans-terminal as `Sans TPE` bucket (V1.0.1.x defers UI), or (B) ship terminal selector UI now + seed 1 default `Sans TPE`-labelled terminal per branch (closes Stage B in PR-A) ; WHERE=`config/pos.php` (add `card_terminal_required` flag) + `app/Http/Requests/PosOrderRequest.php:114-119` + (if B) `resources/js/components/admin/pos/PaymentComponent.vue` insert selector. Default if no answer: A (matches BRAIN §3 line 144 "Stage B deferred V1.0.1.x").

**G2 — LOCK POS wizard XSS (frozen-zone, NOT PR-A but surfacing).** `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` pending countersign — independent owner action; PR-A unblocked regardless.

## §6 Risk register + rollback

- **R1 NF525 chain (T-1)** — 2nd movement per overpay changes Z-report aggregation. Mitigation: same `DB::transaction`. Rollback: `git revert` + reconcile reseed.
- **R2 terminal_id sim flip (T-2/A)** — prod misconfig with sim=true skips CARD enforcement. Mitigated by `AppServiceProvider.php:81-87` boot guard. Rollback: revert + `POS_SIMULATION_HARDWARE=false`.
- **R3 Idempotency canonical (T-3)** — owner policy on "same payload + diff key" needed; default preserves current behavior. Rollback: revert + cache clear `pos_order_idempotency_*`.
- **R4 Index migration (T-7)** — brief MySQL table lock on `item_branch_availability`. Mitigation: maintenance window per daily-backup runbook. Rollback: drop migration.
- **R5 AR translation quality (T-5)** — DeepL output may be non-idiomatic. Mitigation: owner review pre-V1.0.2 freeze; still better than mixed-script.

## §7 References

- `CLAUDE.md` §6/§7/§8/§9/§10 (mandates + frozen zones + NF525 + multi-tenant + decisions).
- `PROJECT_BRAIN.md` §2 (HEAD `a34d1f696` / branch `v1-0-1-hardening-2026-05-17`), §3:144 (terminal_id Stage B deferred).
- Memory: `project_pos_payment_fix_2026-05-18.md`, `project_pos_first_page_oss_filter_2026-05-18.md` (R-2 closed as T-6), `feedback_wizard_popup_pos_protected.md`, `feedback_pos_simulation_hardware_pattern.md`.
- Wave Z `reports/test-e2e/wave-z-2026-05-16-claudemax/CONVERGENCE_FINAL.md` (Z3-NEW-001, P1-Z7-01).
- Insights heal `reports/audit/v1-cloud-prep-insights-2026-05-18/INSIGHTS_FINAL.md` (sim production guard + Stripe cents + POS IDOR 403/404 — closed pre-PR-A).
- LOCK plan `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` (frozen-zone, NOT in PR-A scope).

---

**PR-A wall-clock estimate (10 tasks, subagent-driven):** ~6-8h end-to-end (TDD per task + e2e/visual gate + smoke). G1 owner gate adds 0-30 min.
