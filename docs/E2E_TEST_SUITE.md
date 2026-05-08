# FoodKing — E2E Test Suite Reference

> **Status :** living document — derived from `plans/PLAN_AUDIT_F017_MASSIVE_E2E_TEST_SUITE_2026-05-08.md`.
> **Owner :** QA + executor agents (Waves 4.1 / 4.2 / 4.3 / 4.4).
> **Audience :** anyone running, extending, or debugging the FoodKing end-to-end test fleet.

---

## 1. Why this exists

The unit + feature test fleet (1717+ PHPUnit tests) proves that individual code paths behave correctly in isolation. It does **not** prove that the integrated system holds together under realistic, multi-surface, concurrent operating conditions. F-017 closes that gap with a 10-suite end-to-end battery exercising:

- POS happy-path and edge cases (Suites 1, 2)
- Kiosk happy-path and edge cases (Suites 3, 4)
- KDS multi-tab synchronisation (Suite 5)
- Stock rupture multi-surface propagation (Suite 6) — gate critique F-016
- Stress / load (Suite 7) — rush midi simulation 50–100 concurrent orders
- Multi-branch isolation (Suite 8) — invariant non-négociable
- NF525 fiscal compliance (Suite 9) — gate fiscal absolu
- Reconciliation flows (Suite 10) — F-008 / F-009 / F-003 / F-006 idempotency

Wave 4.3 owns Suites 5 + 8 + 10 + this document + the npm scripts. The other waves own their respective suites.

---

## 2. Layout

```
tests/
├── e2e/                       ← Playwright specs (UI-side)
│   ├── 01-auth-refresh.spec.js          ← smoke baseline
│   ├── 02-pos-cash.spec.js              ← smoke baseline
│   ├── 03-kiosk-wizard.spec.js          ← smoke baseline
│   ├── 04-kds-status.spec.js            ← smoke baseline
│   ├── pos-happy-path.spec.js           ← Wave 4.2 — Suite 1
│   ├── pos-edge-cases.spec.js           ← Wave 4.2 — Suite 2
│   ├── kiosk-happy-path.spec.js         ← Wave 4.2 — Suite 3
│   ├── kiosk-edge-cases.spec.js         ← Wave 4.2 — Suite 4
│   ├── kds-sync.spec.js                 ← Wave 4.3 — Suite 5
│   ├── stock-rupture-sync.spec.js       ← Wave 4.1 — Suite 6
│   ├── concurrent-orders.spec.js        ← Wave 4.4 — Suite 7 (Playwright volet)
│   ├── multi-branch-isolation.spec.js   ← Wave 4.3 — Suite 8 (Playwright volet)
│   ├── nf525-compliance.spec.js         ← Wave 4.1 — Suite 9 (volet UI si applicable)
│   └── reconciliation-flows.spec.js     ← Wave 4.3 — Suite 10 (Playwright volet)
│
├── Feature/
│   ├── Fiscal/
│   │   └── NF525ComplianceE2ETest.php           ← Wave 4.1 — Suite 9 (volet PHPUnit)
│   ├── Isolation/
│   │   └── MultiBranchIsolationE2ETest.php      ← Wave 4.3 — Suite 8 (volet PHPUnit, 8 scénarios)
│   └── Reconciliation/
│       └── ReconciliationFlowsE2ETest.php       ← Wave 4.3 — Suite 10 (volet PHPUnit, 6 scénarios)
│
└── load/
    └── rush-midi-simulation.spec.php            ← Wave 4.4 — Suite 7 (volet stress backend)
```

> **Important :** the PHPUnit `Feature` tests are the **authoritative proof** for backend invariants (BranchScope, idempotency, fiscal allocation, variance). They run in CI without a dev server. The Playwright specs add UI-side evidence (sync latency observation, surface integrity, no-fatal) but are necessarily lighter on backend invariants.

---

## 3. How to run

### 3.1 npm scripts (added in Wave 4.3)

```bash
# Full suite — runs every Playwright spec under tests/e2e/
npm run test:e2e:full

# Smoke subset — critical path only, target <5min for CI
npm run test:e2e:smoke
```

`test:e2e:smoke` runs the four baseline specs (`01-auth-refresh`, `02-pos-cash`, `03-kiosk-wizard`, `04-kds-status`) plus `stock-rupture-sync.spec.js` (the F-016 gate). This subset is intended for the per-PR CI run.

### 3.2 PHPUnit (always runnable)

```bash
# All E2E feature suites
php artisan test --filter='Isolation\\|Reconciliation\\|Fiscal'

# Wave 4.3 specifically
php artisan test --filter='MultiBranchIsolationE2ETest|ReconciliationFlowsE2ETest'
```

These tests use `RefreshDatabase` and require no external services.

### 3.3 Playwright (requires dev server + optionally Soketi)

```bash
# Default : Playwright will start `php artisan serve` if 127.0.0.1:8000 is free.
npx playwright test tests/e2e/kds-sync.spec.js
npx playwright test tests/e2e/multi-branch-isolation.spec.js
npx playwright test tests/e2e/reconciliation-flows.spec.js

# To reuse an already-running dev server (faster, no port races) :
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://localhost:8000 \
  npx playwright test tests/e2e/

# To run against a real WebSockets harness (Soketi local) :
bash scripts/ci-bootstrap-websockets-harness.sh
npx playwright test tests/e2e/kds-sync.spec.js
bash scripts/ci-teardown-websockets-harness.sh
```

See `docs/runbooks/CI_WEBSOCKETS_HARNESS.md` for harness details.

### 3.4 Environment variables

| Var | Default | Purpose |
|---|---|---|
| `PLAYWRIGHT_BASE_URL` | `http://localhost:8000` | Target URL |
| `PLAYWRIGHT_NO_WEB_SERVER` | unset | Set to `1` to skip the auto-spawned `artisan serve` |
| `E2E_POS_USER` / `E2E_POS_PASS` | `pos@lecayenne.fr` / `123456` | Cashier login fixture |
| `E2E_CHEF_USER` / `E2E_CHEF_PASS` | `chef@lecayenne.fr` / `123456` | Chef KDS login fixture |
| `E2E_ADMIN_USER` | unset | Optional admin fixture (skips admin-gated tests if missing) |

---

## 4. Suite-by-suite reference (Wave 4.3 scope)

### Suite 5 — KDS Sync E2E
- **Spec** : `tests/e2e/kds-sync.spec.js`
- **Pattern** : `browser.newContext()` per surface (POS / Kiosk / KDS / OSS) for token isolation.
- **Scenarios**
  1. POS create → KDS reflect <2s (Outbox + Pusher).
  2. Kiosk paid → KDS reflect <2s.
  3. KDS state transitions (ACCEPT → PREPARING → PREPARED → DELIVERED) reflect to OSS.
  4. Branch isolation : chef branch A never sees branch B (UI-side check, fully proven backend-side in `BranchIsolationTest::test_chef_kds_does_not_leak_other_branch_orders`).
  5. KDS polling fallback survives 30s Pusher silence (F-03 Lot 1.C).
- **Caveats** : true broadcast latency requires Soketi/Pusher live. Without a harness, scenarios degrade to surface-integrity sanity. Backend Outbox + dispatch behaviour is proven by `tests/Feature/Outbox*Test.php` and `tests/Feature/EventContractTest.php`.

### Suite 8 — Multi-Branche Isolation
- **Specs** : `tests/e2e/multi-branch-isolation.spec.js` (UI), `tests/Feature/Isolation/MultiBranchIsolationE2ETest.php` (PHPUnit, authoritative).
- **Scenarios** (8) : cashier scope (S1), cross-branch GET (S2), cross-branch POST (S3), admin global (S4), kiosk cross-branch reconcile reject (S5), availability broadcast channel scope (S6), Z report disjoint per branch (S7), cash drawer session per branch (S8).
- **Pattern** : sanctum `actingAs` + `BranchScope` global + HTTP probes against `/api/admin/pos-order/*`. The `S5` scenario specifically exercises the F-008 cross-branch reconcile rejection (`PaymentReconcileController` returns `unauthorized` and writes no audit row).

### Suite 10 — Reconciliation Flows
- **Specs** : `tests/e2e/reconciliation-flows.spec.js` (UI), `tests/Feature/Reconciliation/ReconciliationFlowsE2ETest.php` (PHPUnit, authoritative).
- **Scenarios** (6) :
  1. F-008 reconcile-pending happy path (UNPAID → PAID + fiscal allocation).
  2. Per-entry isolation : OK + amount mismatch + missing order.
  3. F-009 cash acknowledge — **divergence implementation** : the plan referenced an endpoint `/cash-acknowledge` ; the real flow is `OrderService::collectKioskCash` → `PaymentService::confirmCounterPayment` → records a `cash_movement (TYPE_ORDER_PAYMENT, DIRECTION_IN)` and promotes `PENDING_COUNTER → PAID` + allocates `fiscal_sequence_no`. The PHPUnit test exercises the **real** flow (cf. `F009KioskCashCounterDeferredInvariantSentinelTest::test_F009_INV_4`).
  4. Drawer fail signal (best-effort `recordMovement` on closed session returns `null`).
  5. F-006 idempotency replay : same transaction id → `already_paid` + UNIQUE constraint (1 audit row).
  6. F-003 cash session close + reconcile : opening 100 + cash movement +30 → expected 130 ; closing 125 → variance −5 ; status = `RECONCILED`.

---

## 5. Frozen-zone discipline

Tests can EXERCISE the frozen-zone code paths (POS Vanilla JS, kiosk wizards, OrderStateMachine), but MUST NEVER MUTATE them. F-017 plan §0 :
> "Frozen-zone override : NO (tests autorisés sur frozen wizards par mémoire owner)"

Anti-drift rules (plan §7) :
- No modification of business logic to make a test pass — if a test fails, it's a bug.
- Tests must be stable / deterministic — no flaky `xit` / `test.skip` without justification.
- Acceptable `test.skip` with documented reason : env fixture missing (admin user, Soketi harness), surface deferred to another Wave.

---

## 6. Acceptance criteria mapping

| AC (plan §2) | Coverage | Owner |
|---|---|---|
| AC1 — 10 suites | All under `tests/e2e/` or `tests/Feature/{Isolation,Reconciliation,Fiscal}` | Waves 4.1–4.4 |
| AC2 — >60 scenarios | Suite 5 (5) + Suite 8 (8 + 8 UI) + Suite 10 (6 + 6 UI) = 33 from W4.3 alone | W4.3 |
| AC3 — All pass in CI | PHPUnit ✅ ; Playwright pending dev server | All |
| AC4 — <2s P95 sync | Spec budget = 2 000 ms ; observation requires harness | W4.3 |
| AC5 — 0 fiscal/queue collision over 200 orders | Suite 7 stress | W4.4 |
| AC6 — 0 regression on 1717 existing | Verified per-commit | All |
| AC7 — F-016 stock rupture sync | Suite 6 | W4.1 |
| AC8 — F-001 NF525 invariant | Suite 9 | W4.1 |
| AC9 — F-003 cash variance | Suite 9 + Suite 10 S6 | W4.1 + W4.3 |
| AC10 — F-008 reconcile-pending | Suite 10 S1 + S5 | W4.3 |
| AC11 — Multi-branch isolation 8 scénarios | Suite 8 (PHPUnit) | W4.3 |
| AC12 — Stress 50+50 simultané | Suite 7 | W4.4 |
| AC13 — Outbox 100 events <30s | Suite 7 | W4.4 |
| AC14 — This document | Wave 4.3 | W4.3 |
| AC15 — `npm run test:e2e:full` | Added in Wave 4.3 | W4.3 |
| AC16 — `npm run test:e2e:smoke` (<5min) | Added in Wave 4.3 | W4.3 |

---

## 7. Failure triage

When a Wave 4.3 test fails :

1. **PHPUnit fail** → reproduce locally, inspect the assertion. Three categories :
   - True regression in production code → file a P0/P1 ticket, do **not** modify the test to pass.
   - Test data drift (seed, factory) → fix the test setup.
   - Flaky time-dependency → improve setup, never rely on real wall-clock without `Carbon::setTestNow`.
2. **Playwright fail** → check the screenshot in `tests/e2e/screenshots/` + the JSON report in `reports/antigravity/playwright-latest.json`. Three categories :
   - Surface fatal (Whoops / 500) → backend regression, escalate.
   - Selector miss → SPA UI changed ; update selector to a more stable one (prefer `[data-testid]`).
   - Network 4xx noise → check `helpers/login.js` for rate-limit handling and `clearFoodKingRateLimits()`.

---

## 8. Maintenance

- Each PR touching surfaces in scope (POS, kiosk, KDS, payment, fiscal, branch isolation) **must** include the relevant suite run in its evidence pack.
- The smoke subset is the **mandatory CI gate** ; the full suite is run nightly (or before merge to `main`).
- Every new feature affecting one of the 10 surfaces should add at least one scenario to the matching suite.

---

## 9. Cross-references

- Plan : `plans/PLAN_AUDIT_F017_MASSIVE_E2E_TEST_SUITE_2026-05-08.md`
- WebSocket harness runbook : `docs/runbooks/CI_WEBSOCKETS_HARNESS.md`
- Outbox dashboard runbook : `docs/runbooks/OBSERVABILITY_OUTBOX_DASHBOARD.md`
- Existing massive test plan (legacy) : `docs/MASSIVE_TEST_PLAN.md`
- Smoke baseline : `tests/e2e/01-auth-refresh.spec.js` … `04-kds-status.spec.js`
- Frozen zones : `~/.claude/projects/.../memory/reference_frozen_zones.md`
