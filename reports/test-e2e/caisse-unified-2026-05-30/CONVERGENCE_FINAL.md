# Caisse-Unifiée GOAL — Convergence Final

**Date**: 2026-05-30 · **Branch**: `heal/cms-pr1-quickwins-2026-05-18` · **Owner /goal**: caisse unifiée + historique (2 waves) → abuse-e2e en boucle jusqu'à validation.

## Verdict: ✅ CONVERGED — GO V1 LOCAL (1 owner-gate for delta-B activation)

Two consecutive adversarial rounds: **round 1** = 1 P1 (pre-existing + owner-gated, NOT introduced) + 4 P3 (all healed); **round 2** = **0 new P0/P1**, all 4 P3 resolved, frozen-integrity independently attested CLEAN. Convergence criterion (2 cycles, P0+P1=0 new) met.

## What was built (4 commits, all non-frozen)

| Wave | Commit | Summary |
|---|---|---|
| W-HIST | `1c1701004` | Unified read-only `/admin/historique` — every origin (Borne/Caisse/walk-in/delivery/online) in ONE table with an origin badge + NF525 columns (fiscal_seq, refund link), filters, gated endpoint. New `OrderHistoryController` over `OrderService::list`; `SimpleOrderResource` +fiscal_sequence_no/+parent_order_id; `OrderService` +source_surface filter; orderHistory store + historiqueRoutes + 2 Vue components + nav + i18n. |
| W-ENC | `d60acdfe2` | Unified `/admin/encaissement` collection queue — cash+card via the shared `PosCounterCollectModal` + `confirmCounterPayment`, origin badge, 20s poll. Dashboard quick-access links (D3). `OrderDetailsResource` +source_surface. |
| delta-B | `b297e39d4` | POS walk-in → unified counter-collection (config-gated `pos.walkin_route_to_counter`, **default OFF**). `posOrderStore` deferred branch (PENDING_COUNTER + COUNTER_DEFERRED + CASH_ON_DELIVERY, skips fiscal alloc); `PaymentService::assertCounterDeferredOrder` accepts pos-origin; `counter-collect/pending` additive OR-clause. |
| abuse-e2e P3 heals | `ad9457382` | 4 P3 from round 1: authz gate tightened (drop online-orders), origin source-fallback, ar.json label.kiosk, pending queue cap 50→200. |

Owner decisions logged BRAIN §6: **D1** (reverse Wave S-2 — kitchen prepares before pay), **D2** (unified encaissement, model B), **H-03** (paid-only revenue, `4b4bd2591`).

## Owner gate (NOT actioned autonomously)
**delta-B activation** — flipping `POS_WALKIN_ROUTE_TO_COUNTER=true` (or wiring a per-order "Payer à la caisse" control in the non-frozen PosComponent) changes the owner-protected POS checkout UX. Built + tested + reversible, default OFF. See `DELTA_B_GATE_CHECK.md`.

## Adversarial findings

### Round 1 — 1 P1 + 4 P3 (6 lenses, each finding independently verified)
- **[P1] escape-z** `OrderService::changePaymentStatus` seals PENDING_COUNTER→PAID without allocating fiscal_seq → escapes signed Z. **PRE-EXISTING** (`git diff` shows 0 changePaymentStatus edits in range), reachable via the pos-order show dropdown. Its naive fix (allocate fiscal there) = commit `1808f9494`, **REVERTED** `3a4744e63` (creates cross-Z-window numbered orphans); owner chose **detect-only** (`fiscal:verify-z-membership` cron). **NOT re-applied — anti-drift (CLAUDE.md §12).** delta-B amplifies the PENDING_COUNTER population but is **gated default-OFF → zero live exposure**. Stays the existing owner-gated NF525 backlog item; activating delta-B should be gated behind the owner's cross-window settlement policy decision.
- **[P3] authz** order-history gate included `online-orders` (broader than the pos-order surface). **HEALED** → `pos-orders||pos`. (Not exploitable — no seeded role had online-orders alone.)
- **[P3] data-origin** origin resolver blanket-badged legacy NULL-`source_surface` orders "En ligne". **HEALED** → `source`-column fallback (POS→Caisse, WEB/APP→En ligne).
- **[P3] ui-i18n** `label.kiosk` missing in `ar.json` (Arabic showed French "Borne"). **HEALED**.
- **[P3] sync-lifecycle** `counter-collect/pending` `limit(50)` hid orders beyond 50 (60 pending). **HEALED** → limit 200, FIFO ASC kept.

### Round 2 — 0 new P0/P1 (CONVERGED)
- nf525-escape-z 0/0 · authz 0 confirmed (raised→refuted, heal verified) · data-origin 0/0 · ui-i18n 0/0 · sync-lifecycle 0/0.
- **frozen-integrity** = independently attested **PASS**: `git diff 7a1db2dce~1..HEAD` touches ZERO frozen files; new code routes through frozen subsystems only via their PUBLIC APIs (`FiscalSequenceService::next`, `OrderStateMachine::recordTransition`, `confirmCounterPayment`); `pos-wizard.js` (296912 B, mtime May 9, absent from mix-manifest) untouched; admin-shell.js/pos-app.js are expected Mix rebuilds of the new non-frozen components.

## Gates (final)
- **vitest 1882** (275/275 files, 1879 passed; 4 errors = pre-existing ECONNREFUSED:3000 noise)
- **PHP full suite 2727 passed** / 0 failed (1 risky + 2 incomplete + 29 skipped = pre-existing baseline; +11 new caisse-unifiée tests)
- **NF525 CHAIN OK** (SWEEP COMPLETE, branch=1)
- **Frozen-zone 0** (independently attested)
- 18 new feature tests across the GOAL: OrderHistoryUnified (4) + PosWalkinCounterCollect (4) + PosWalkinDeferredCreate (3) + supervisor-audit e2e realignments.

## Live evidence (driven, not test-theater)
- `/admin/historique`: 10 FR columns, origin badges Borne/Caisse, fiscal col correct (cash-pending `—`, paid gap-free 167→160), 0 raw labels, 0 console errors; filters verified (all=353, kiosk=141, pos=150, paid=228).
- `/admin/encaissement`: real NF525 collection cycle — A0031 PENDING_COUNTER→PAID, fiscal NULL→**168** (gap-free), audit_logs +2, CHAIN OK, order left queue; count chip now **60** (true, was capped 50).
- Screenshots: `screenshots/caisse-unified-{historique,encaissement,encaissement-modal}-01.png`.

## No push (owner gate per CLAUDE.md §10).
