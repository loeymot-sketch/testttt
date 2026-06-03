# GOAL_MGMT_TESTPLAN — CONVERGENCE FINAL (Waves A–C)

**Date:** 2026-06-03 · **Branch:** `heal/cms-pr1-quickwins-2026-05-18` · **HEAD:** `59c95085a` (+ cash-overview tighten)
**Plan:** `plans/GOAL_MGMT_TESTPLAN_2026-06-01.md` · **Owner /goal:** "execute all plans, visual real test-e2e at each step, adversarial, perfection, everything works."

## VERDICT: ✅ CRUCIAL SPINE CONVERGED — management surface audited page-by-page, 0 P0/P1

Scope executed = the **executable-now** slice (triaged from "all plans"): GOAL_MGMT_TESTPLAN Waves A (pre-flight), B (crucial spine: A5 Historique + A6 Cash + A1 Dashboard), C (read-side breadth A2/A3/A4). **Owner-gate items NOT auto-executed** (surfaced below): physical go-live G1–G8, post-soak destructive Wave D/E.

### Gates (all me, not agent self-cert)
- **Full PHPUnit suite: 2807 passed, 0 failed** (29 skipped, 2 incomplete, 1 risky — all pre-existing). The 7 new `:memory:` tests integrate without pollution.
- **Frozen-zone source diff = 0** — NO product source changed this /goal (both owner-approved "fixes" were already in HEAD).
- **NF525 chain CHAIN OK** all branches.
- **Adversarial RED dispute: 13/14 tests HARD, no P0/P1 missed** (HIST-08 + HIST-13 source-verified); 1 P2 (cash-overview conditional assert) → **tightened**.

## Owner decisions (AskUserQuestion, up front to avoid hook deadlock)
- **COUPON-CAP-01 → "Corriger".** Found ALREADY enforced on HEAD (`CouponService.php:454-458`, count-based via `order_coupons`, commit `71107be77`). Strengthened the regression lock; **declined** the prescribed `usage_count` increment (would create a divergent counter the cancel-path can't decrement → false-blocking). No source change.
- **DASH-01 → "Compter toutes les commandes".** Found ALREADY implemented (`DashboardService::totalOrders()` counts all placed, excludes refund mirrors, commit 2026-06-01). Added a locking test. No source change.

## Wave B — CRUCIAL SPINE (14 new tests, all GREEN, hard-asserted)

### A5 Historique (owner's #1 fear: "is data well recorded?")
| Test | Verdict | Proof |
|---|---|---|
| HIST-08 cross-branch order detail → 403 | ✅ HARD | `OrderHistoryController:81-89` withoutGlobalScope + explicit branch 403; own-branch-200 + admin-200 controls; **no leak** |
| HIST-10 composition_snapshot frozen on read | ✅ HARD | mutation-probe: live price→999 ignored, detail returns frozen 7.50 (NF525 §8) |
| HIST-13 OSS public wall ships NO PII | ✅ HARD | `CDSOrderDetailsResource` 6-key whitelist; PII (name/phone/email/addr) absent; mis-seed guard |
| HIST-05 NULL/dirty source_surface fallback | ✅ HARD | `SimpleOrderResource:58` verbatim; never coerced to a wrong concrete origin |
| HIST-04 'En ligne' filter (source_surface=web) | ✅ HARD | id+count-precise include/exclude; **latent finding** (see below) |

### A6 Encaissement / Cash
| ENC-13 Cash Overview visual | ✅ | real € totals (Grand Total, by-source caisse/borne/livreur, reconciliation), no NaN/raw-key; per-card € validation now unconditional |
+ existing ENC-01…12 (cash-trail, reconciliation, branch isolation, split-payment) GREEN in the 2807-suite.

### A1 Dashboard + Navigation
| Test | Verdict | Proof |
|---|---|---|
| **DASH-T10 ⭐ every nav button → working page** | ✅ HARD | **25/25** nav targets reach real routed content (guard strips header+sidebar, fails on blank/login-bounce/pageerror/raw-key). **Zero orphans.** |
| DASH-T11 V1-hidden modules absent from sidebar | ✅ HARD | 9 hidden keys absent; DB-sourced positive control; exact-href match |
| DASH-T12 RBAC sidebar permission-filtering | ✅ HARD | admin **29** vs POS-operator **11** labels; 18 sensitive sections (Users/Settings/Transactions/Catalogue/Reports…) hidden; strict-subset + non-vacuity guard |
| DASH-T13 visual integrity @1920/@2560 | ✅ HARD | KPIs populated, no NaN/overflow/raw-key |
| DASH-T02 "Total commandes" count-all | ✅ HARD | multi-status mix; mirror-exclusion; DELIVERED-only revert would fail |
| HIST-11 historique table visual | ✅ | Borne/Caisse badges (human labels), 3430 rows, filter works, honest empty-state |
| HIST-12 historique nav (sidebar+tile+row Voir) | ✅ | all 3 paths reach working pages; order detail #0306264139 renders real content |

## Wave C — read-side breadth (A2/A3/A4)
- Existing Catalog/Stock/Availability/Ingredient/Coupon/Offer/Composer pools: **403 passed, 0 failed**.
- Visual read: catalogue (45 items, 11 categories, prices, "Actif") + stock-rupture (21 buckets, 5 "EN STOCK" cards) render clean, no NaN/raw-key.

## Residual (non-blocking — documented, owner decides)
- **HIST-04 latent (P2/P3):** the "En ligne" badge labels web|app|mobile|legacy-NULL but the filter sends only `source_surface=web` → a legacy/NULL-surface online row badged "En ligne" can vanish under that filter. Affects ONLY legacy/NULL rows; new online orders carry `source_surface='web'`. Owner: tighten badge vs broaden filter.
- **Catalogue price formatting (P3):** prices render bare (`1.50`) without the € symbol on the catalogue list (`item.flat_price`). Pre-existing formatting choice.
- **DASH-T12 server-contention flake (environmental):** times out on login-input when run immediately after the 2.6-min DASH-T10 sweep on the single dev `serve`; **passes clean in isolation** (9.7s) and on retry. Not a spec/product defect. Mitigate via `--retries` or isolated runs.
- **Cash-cap & coupon-cap TOCTOU (V2/SaaS line):** count-based caps are check-then-act; non-event in the V1 single-box/single-cashier envelope.

## Owner-gate items SURFACED (not executed — owner/physical only)
G1 post-soak destructive-wave authorization · G2/G3 (resolved above, already-shipped) · G4 orphan-page wiring (none found — 25/25 reachable) · physical go-live G5–G8 (.env flip, Ansible REVOKE, migrate:fresh seed, on-site walk + real signed Z) · Wave D (Settings/Users/Notifications CRUD) + Wave E (Reports) — destructive, deferred to post-soak per §0.5.

## Convergence evidence
Round 1 (authoring) all green → Round 2: backend mgmt set 17 passed, cash-overview 5/5 (tightened), DASH-T10 25/25, DASH-T12 clean-isolated. Full PHPUnit 2807/0. No push (owner gate).

## "Done" definition honored (not "all 143 tasks")
Crucial spine (A5+A6+A1) converged P0+P1=0; every nav button proven-to-reach-working-page (25/25, 0 orphan); data-recording invariants asserted (snapshot frozen, cross-branch 403, no-PII, count-all, RBAC); Wave C read-side green; adversarial RED done; owner-gates surfaced/decided. Destructive Wave D/E + physical go-live remain owner-only.
