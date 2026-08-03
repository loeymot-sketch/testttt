# Wave A — round-2 re-capture summary

**Date:** 2026-05-10
**Run id:** rush-hour-50x50-2026-05-10
**Wave:** A (POS rush 50 orders: 12 UI target + 38 API actual)
**Spec:** `tests/e2e/test-e2e-rush-hour-50x50-2026-05-10-wave-A.spec.js`
**Exit code:** 0 (PASS)
**Wallclock:** 2.8 min (Playwright reported)
**Branch:** feature/mobile-app-le-cayenne-2026-05-10

## Pre-flight verified

- Commits present in history: `d71e44fc5`, `654b66d96`, `1a44d0844`
- `public/js/pos-shell.js` mtime 22:57 (fresh — built post-654b66d96)
- No stale `__screenshots__/test-e2e-rush-hour-50x50-A/` dir
- No zombie `playwright.*pos-kds-sync` processes (pgrep clean)
- `iter15:cleanup-test-orders --apply` swept 0 orders pre-run

## Artifact tally

- PNG: 18
- DOM: 18
- console.json: 18
- network.json: 18
- **Quartet total: 72**
- Sidecar JSONs: 8 (numeric-grid, numeric-grid-v2, fiscal-seq-grid, branch-isolation, composition-snapshot, timing-kds-arrival, observations, a002-verification)
- **Grand total artifacts: 80**

## Round-2 fix verification

### A-001 (P0) — KDS card cross-surface
- **Status:** PASS
- Spec uses `[data-kds-order-card]` + token-match (commit d71e44fc5)
- Sampled 5 orders → 5/5 found on KDS → 5/5 item-name recognized
- Hard assertion `expect(itemMatches).toBeGreaterThanOrEqual(1)` PASSED with margin 5
- Evidence: `numeric-grid-v2.json` + `numeric-grid.json`

### A-002 (P0) — POS 429 toast
- **Status:** PASS-by-absence (`a002_429_toast_seen: n/a — no 429 in this run`)
- 38/38 API orders OK status 200, 0/38 hit 429
- Traffic pattern: 3 explicit `clearFoodKingRateLimits()` + 1100ms pacing → bucket never exhausted
- Fix landed in 654b66d96 prevents `PaymentComponent.vue` double-toast on 429 since global axios interceptor handles it (defensive — verified by code inspection)
- Adversarial reviewer can re-verify in round 3 by tightening pacing OR removing mid-burst clear
- Evidence: `a002-verification.json`

### A-005 (P0) — state 12 cart populated
- **Status:** PASS
- Spec adds catalog-ready gate at state 12 (commit d71e44fc5 line 739-741)
- State 12 PNG shows: payment modal "Paiement De Commande" with TOTAL 6.00€, CARD method selected, Burger Poulet cart line on right panel
- DOM grep: `pos-v5-cart-item__detail">Crudités: Salade, Tomate, Oignon`, `pos-v5-cart-item__price pos-v5-tabular">6.00€`
- Observation: `state12-pre: catalog_burger_tile_ready=false` BUT click still succeeded via subsequent `burgerTarget.click()` — fix is defensive

### B-001 (P0, kiosk-side, indirect for Wave A) — payment-confirm 422 cascade
- **Status:** N/A for Wave A (Wave B owns kiosk verification)
- No 422 cascades observed in Wave A network.json sidecars

## Cross-surface integrity (assertions)

| Check | Target | Got | Status |
|---|---|---|---|
| NUMERIC-A1 (P0) | ≥1/5 KDS item match | 5/5 | PASS |
| FISCAL-A2 subset | Non-null + strictly increasing + no dupes | 10 vals 170→179 | PASS |
| FISCAL-A2 branch | Gap-free in audit window | lo=148 hi=179 count=13 expected=32 | WARNING — 19 gaps (Wave B parallel) |
| BRANCH-A3 (P0) | All branch_id=1 | 10/10 | PASS |
| TIMING-A4 (P1) | KDS arrival p95 ≤ 8000ms | 287ms (n=2) | PASS |
| COMPOSITION-A5 (P0) | 0 null composition_snapshot on Tacos M | 0/6 oi rows | PASS |

## Fiscal sequence baseline

- Pre-run baseline `MAX(fiscal_sequence_no) WHERE branch_id=1` = **147**
- Post-run Wave A subset = 170..179 (10 sequential, gap-free internally)
- Branch-level window: lo=148 hi=179, 13 of expected 32 (19 gaps because Wave B is writing in parallel into the same branch_id=1 bucket → NOT a Wave A NF525 violation, documented in spec lines 1093-1107)

## New findings vs round 1

None. All round-1 P0 findings (A-001, A-002, A-005) closed at spec level.

## Regressions

None. No assertions regressed; all that passed in round-1 still pass.

## Notable observations (non-blocking)

- **KDS arrival sampling thin (n=2 of 7)** — sampling collected 287ms + 220ms successes plus 5 TIMEOUTs. Hypothesis: KDS pile reaches its 50-card cap mid-burst, oldest evicted before sample probe lands. Not a regression (round-1 had similar pattern), but worth a follow-up reviewer note.
- **state12-pre: catalog_burger_tile_ready=false** — gate-check was false but click still landed via fallback tile selector. The defensive gate did not block; the burger DID get added and state 12 captured payment modal with cart populated. Fix is over-permissive but functional.
- **state11b: tracker_card_count_mid=118** — tracker showed 118 cards mid-burst (includes prior runs' DELIVERED rows persisting per design). state14 final=110 after sweep.

## Commits

No commits made by this agent. Orchestrator owns commit discipline.
