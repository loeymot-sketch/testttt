# Abuse-Test-E2E — Convergence Final (2026-06-16)

**Goal (owner):** « abuse test-e2e e corrige » — drive adversarial end-to-end testing hard across the
live FoodKing surfaces, find real defects with interface + technical proof, and FIX them in a loop
until validated.

**Harness:** live `:8766` (DB `foodking_e2e` disposable clone, APP_ENV=e2e, serving this worktree).
Branch `goal/wizard-wysiwyg-builder-2026-06-14`. Bundle rebuilt before each verification.

## VERDICT: ✅ CONVERGED — 0 P0 / 0 P1 across two rounds · 3 actionable P2 defects FIXED + live-verified · frozen 0.

## Method
- **Live browser drive** (Playwright MCP, single browser, visual reasoning) across all 5 systems:
  kiosk idle→categories→product→composer→sauce, dashboard, KDS, OSS wall, POS, encaissement,
  ingredients, stock, login, 404.
- **3 parallel read-only code-audit agents** (BORNE+CAISSE, KDS+OSS, CENTRAL+xcut) attacking defect
  classes with file:line + reproduction.
- **Round-2 adversarial verifier** that tried to REFUTE every fix + re-hunt P0/P1.

## Findings & disposition
| ID | Sev | System | Defect | Disposition |
|---|---|---|---|---|
| CAISSE-FMT-01 | P2 | CAISSE | POS ticket totals/cart rendered en-US `0.00€` (period, glued) via `appService.currencyFormat`, while the rest of the FR app uses `formatPrice` (`0,00 €`, NBSP). Live-confirmed on same page (ticket `0.00€` vs order list `5,30 €`). | **FIXED** `44f08f152` — routed PosComponent's local `currencyFormat` through `formatPrice`. Live: `0,00 €`. Frozen PaymentComponent + appService untouched. Test 3/3. |
| A11Y-EDIT-01 | P2 | CENTRAL | Icon-only row-edit buttons (2 shared components, used across 23 admin tables) had NO accessible name (`.db-tooltip` is `visibility:hidden`). Sibling Delete/View already fixed → incomplete pass. | **FIXED** `44f08f152` — aria-label + title + FR "Modifier" fallback. Live `/admin/items`: 10/10 labelled (was 0/10). Test 4/4. |
| STOCK-GRID-01 | P2 | CENTRAL | `/admin/stock/rupture` cards used viewport `lg:grid-cols-3` but the grid is ~600px behind 2 sidebars → 191px cards → flex-1 name collapsed to 9px, "Sandwich Cayenne" rendered "S a". | **FIXED** `051a0f532` — container-responsive `auto-fill minmax(220px,1fr)`. Live: names full, clipped:false. Test 2/2. |
| Controller `getMessage()` leak | P2 | XCUT | ~75 admin controllers `catch(Exception){ return 422 $e->getMessage() }` — leaks SQL on a rare `QueryException`, mostly auth-gated. | **DEFERRED** — a 75-file sweep is NOT a "no-harm small correction"; documented backlog. |
| Coupon/customer "swallow" twins | P2 | CENTRAL | Subset of the above; round-1 over-claimed P1, round-2 corrected to P2 (`Rule::unique` returns a displayable 422; only a rare service exception hits the silent path). | DEFERRED (same class). |
| Parked-order dedup; report empty-state masking; orphan i18n keys (dead code); e2e-clone test-data orphans (`wval3cg-*` categories, `BORNEAUDIT5` promo) | P3 | various | benign / unreachable / clone-only data | NOTED, not fixed (no production impact; "no DB" mandate). |

## Prior heals re-verified at RUNTIME (all HOLD)
- **KDS-01** OFFLINE banner: agent fired `_emit('disconnected')` + back-dated `v2OfflineSince` → red banner rendered; `connected` cleared it.
- **KDS-02** 403-not-flattened: 6/6, all 3 methods.
- **OSS-01** public-wall toast suppression: mutation-resistant 3/3.

## Evidence
- Full Vitest **1969 passed / 3 skipped** (+9 new across 3 specs). The 3 "errors" = pre-existing flaky `socket hang up`/ECONNRESET teardown in an unrelated network spec (count varies run-to-run, 0 test failures, my specs open no sockets).
- **Frozen diff 0** across the whole goal (15 files) — PaymentComponent/pos-wizard/PricingService/NF525 chain all untouched.
- All 3 fixes are non-frozen, display/a11y-only, behavior preserved, live-verified in the rebuilt bundle.
- Commits: `44f08f152` (CAISSE-FMT-01 + A11Y-EDIT-01), `051a0f532` (STOCK-GRID-01).

## Convergence
Round 1 P0+P1=0; Round 2 P0+P1=0 with both fixes refuted-and-held. The only remaining open items are
the deferred systemic-leak P2 class (out of scope for the no-harm mandate) + benign P3s. Blocking gate
(P0+P1=0) satisfied across two consecutive rounds. Remaining = owner gate (push).
