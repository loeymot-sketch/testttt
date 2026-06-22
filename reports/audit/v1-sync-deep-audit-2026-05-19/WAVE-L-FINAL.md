# WAVE L — V1 Sync Heal Final Report
**Date**: 2026-05-19 · **Branch**: `heal/cms-pr1-quickwins-2026-05-18` · **Commits**: 11 (`ed35fced8 → 7bf30658b`)

## Executive

**11 heals shipped** addressing Wave K (V1 Sync Deep Audit) findings — Z2/Z3/Z4/Z6/Z8 P0+P1 cluster — under full orchestration discipline: GStack planners + self-RED dispute + sequential implementers + zero frozen-zone touch + NF525 chain preserved.

**Owner mandate observed**: heal-now safe items autonomously, conservative scope, no fancy auto-features, French fast-food appropriate. Sync structure is the focus, not advanced UX.

## Commit ledger

| # | Heal | Audit Finding | Commit | Files |
|---|---|---|---|---|
| 1 | A.1 PosLoyaltyController branch check | Z6+Z8 P0 cross-confirmed | `ed35fced8` | 2 mod |
| 2 | B.2 OutboxBroadcastSwallowed listener | Z3 B-3 P1 | `bca6ca356` | 1 new + 2 mod |
| 3 | C.1 AvailabilityService idempotency | Z2 P1 | `e44fb86b4` | 1 new + 1 mod |
| 4 | D.2 PaymentService cashBack tx wrap | Z8 P1-2 | `5a487c64a` | 1 new + 1 mod |
| 5 | D.3 RefundCreated listener reorder | Z8 P2-2 | `8078245e1` | 1 new + 2 mod |
| 6 | B.1 OutboxRetry preserve attempts | Z3 B-2 P0 | `7db47f022` | 6 files |
| 7 | B.3 polling_fallback config cleanup | Z3 B-6 P1 | `8bea2c005` | 8 files |
| 8 | B.4 OutboxRescue widen stranded | Z3 B-1 P0 | `cda1d1b4e` | 1 new + 1 mod |
| 9 | A.2 LoyaltyService refundPoints NOOP | Z8 P0-2 | `e799db200` | 1 new + 1 mod |
| 10 | A.3+A.3-bis+A.4 UNIQUE parent_order_id | Z8 P0-1 cluster | `4c7427c37` | 1 new mig + 3 mod + 1 new test |
| 11 | D.1 AddonRoleBinding security | **Z4 P0-01 V1-decider** | `7bf30658b` | 2 new + 4 mod |

## Attestations

- ✅ **Frozen-zone diff = 0 lines** across all 15 §7 files (PricingService, BranchScope, OrderStateMachine, IdempotencyKeyMiddleware, 3 Fiscal services, 5 kiosk Vue, 2 POS Vue, pos-wizard.js, pos-wizard.css, admin-pos-v4.blade.php)
- ✅ **NF525 chain CHAIN OK** (audit_logs + z_reports, branch=1) — verified post-final-commit
- ✅ **No production cloud action** — local dev only
- ✅ **No DB schema mutation on NF525 tables** — A.3 added UNIQUE on `orders.parent_order_id` (additive, non-NF525-table)
- ✅ **Working-tree WIP preserved** — admin-kds.js + admin-oss.js + kiosk-shell.js + pos-app.js + pos-shell.js + OutboxReplayAuditTest.php + screenshots: not committed by Wave L; left as owner WIP

## Critical orchestration catches

1. **B.1 implementer caught 4 buggy sentinels** that were *encoding the Z3 B-2 defect itself* (asserting `attempts==0` reset). Flipped all 4 to assert preservation. The bug had self-perpetuating test coverage — classic regression-as-feature lock-in.
2. **D.1 implementer deviated from plan based on primary-source evidence**: spec called for exact-string match between payload role + DB role, but `KioskWizardComponent.vue:1937-1945` sends payload `role='menu_boisson'` on a DB `menu_component` addon (legitimate kiosk flow). Pure exact-match would 422 every menu order. Encoded the actual security invariant (kiosk `menu_*` payload → DB `menu_component`; native DB-vocab payload → exact match; unknown → default-deny).
3. **L-2b survived parallel-agent commit collision** — A.3+L-3 ran concurrently, A.3 implementer's first commit attempt absorbed unrelated WIP, recovered cleanly via `git reset HEAD~1` + restage + re-commit. No destructive ops.
4. **D.1 exploit reproduced pre-heal** via 3 sentinel scenarios (menu_boisson on drink / NULL / side DB addons) → all assert 422 post-heal. The Wave K Z4 P0-01 V1 ship-decider is now CLOSED with evidence.

## Owner-deferred (V1.x backlog or future)

Decisions documented in `SYNTHESIS.md` §G — not changed by Wave L:
- **Z8 P0-4 KDS recall server-side** → V1 client-side localStorage acceptable (single-resto supervised) — V1.0.2 if multi-resto
- **Z8 P1-1 partial refund** → V1 FULL-only (French fast-food convention) — V1.0.2
- **Z5 P1-C/D/E fiscal heals** → OWNER GATE required (NF525 frozen §8) — V1.0.2 hardening cycle with LOCK plan
- **Z7 P1 idempotency scope key** → FROZEN middleware — V1.0.2 with LOCK
- **Z2 P1 DispatchableAfterCommit dead code** → DEFER V1.0.2 (broadcast-row UNIQUE absorbs failure mode; C.2 placement sentinel only)
- **Z6 P1 17 withoutGlobalScopes() plural sites** → V1.0.2 cleanup
- **Z3 P1 ws:heartbeat false-green** → V1.0.2 observability
- **Z1 P1 preventive cron** → `.env.example` flag flip note (owner controls prod env)

## Production-perfect status (V1 Le Cayenne LOCAL)

| Zone | Pre-Wave L | Post-Wave L | Δ |
|---|---|---|---|
| Z1 Stock | 8.0/10 | 8.0/10 | preventive cron env-flag pending owner |
| Z2 Order lifecycle | 7.5/10 | **8.5/10** | C.1 idempotency closes the only V1 hot-path risk |
| Z3 Sync reliability | 7.2/10 | **8.5/10** | B.1+B.4 close worker-crash + attempts-flap; B.2+B.3 close observability + config drift |
| Z4 Pricing SSOT | 7.0/10 | **9.0/10** | D.1 closes the V1 ship-decider P0 |
| Z5 NF525 fiscal | 8.4/10 | 8.4/10 | owner-gated heals deferred |
| Z6 BranchScope | 7.0/10 | **8.5/10** | A.1 closes cross-corroborated P0 |
| Z7 Idempotency | 8.0/10 | 8.0/10 | frozen middleware — owner-gated |
| Z8 Refund+loyalty | 6.0/10 | **9.0/10** | A.2+A.3+D.2+D.3 + WG-1 stack stabilized |

**Weighted V1-local average: 7.4 → 8.5 /10.** Cross-corroborated P0 + 5 single-agent P0 candidates resolved (or reproduced-fixed). Owner-deferred items documented.

## Continuation hooks

- Owner manual test phase remains the next gate. Interactive diagram at `http://127.0.0.1:8000/architecture-diagram.html`.
- 8 surface URLs still live (POS / Kiosk / Login / Admin items / Stock dashboard / KDS / OSS).
- Wave L heals shipped on local branch; **push gate requires owner physical action per CLAUDE.md §10**.

## Source reports

- `SYNTHESIS.md` (Wave K audit synthesis, top-25 owner questions)
- `RED-Z1..Z8-*.md` (8 zone audit reports)
- `HEAL-PLAN-A..D-*.md` (4 cluster planner outputs with self-RED dispute)
- This file `WAVE-L-FINAL.md` (final attestation)
