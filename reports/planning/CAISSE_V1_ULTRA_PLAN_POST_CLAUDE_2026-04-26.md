# Planning Report — Caisse V1 Ultra Plan Post Claude

Skill used: `report-to-plan`.
Input: Claude ultra audit pasted by human + latest local execution evidence.

## Issue Summary

FoodKing Caisse V1 is not ready for implementation continuation because governance is still unresolved. The technical red areas are actionable, but the repository state is not yet trustworthy enough to execute long waves safely.

Main risks:

- dirty/untracked worktree and closed missions not yet persisted;
- dual active cycle state;
- 44 PHPUnit failures and 6 Vitest failures;
- branch/KDS/kiosk/offline queue true product bugs;
- backend invariant issues identified by audit;
- frontend POS monolith and catalogue gaps;
- release proof gaps for hardware, fiscal, staging, and runbooks.

## Root Causes

- Governance closure lagged behind implementation velocity.
- New quote/outbox contracts are stricter than legacy tests.
- Some true runtime bugs remain hidden inside broad flows: KDS visibility, kiosk branch resolution, offline queue key preservation.
- POS frontend and OrderService are still monoliths, making precise changes harder.

## Affected Modules

- POS backend: `OrderService`, `PosOrderController`, `OrderQuoteService`, `PaymentService`.
- POS frontend: `PosComponent.vue`, POS stores, payment component.
- KDS/sync: KDS service/controller, realtime consumers.
- Kiosk offline: offline queue helper/store.
- Catalogue/pricing: `PricingService`, item visibility/projection, tax model.
- Governance: gates, memory episodes, active cycle, closed-vs-git report.

## Routing

- Phase A: human-led governance, Codex report generation only.
- Phases B-F: Codex implementation after Phase A.
- Phase G: Codex + human proof gathering.
- Claude: no automatic invocation requested here; future independent audit only when the user explicitly asks or when the run-cycle audit mode requires it.

## Plan Artifacts Created

- `plans/PLAN_CAISSE_V1_ULTRA_FINITION_POST_CLAUDE_2026-04-26.md`
- `plans/caisse-v1-ultra-finition/README.md`
- `plans/caisse-v1-ultra-finition/TASK_REGISTRY_2026-04-26.md`
- `plans/caisse-v1-ultra-finition/PHASE_A_GOVERNANCE_2026-04-26.md`
- `plans/caisse-v1-ultra-finition/PHASE_B_TEST_STABILIZATION_2026-04-26.md`
- `plans/caisse-v1-ultra-finition/PHASE_C_BACKEND_SECURITY_2026-04-26.md`
- `plans/caisse-v1-ultra-finition/PHASE_D_POS_FRONTEND_REFACTOR_2026-04-26.md`
- `plans/caisse-v1-ultra-finition/PHASE_E_CATALOG_POS_LINK_2026-04-26.md`
- `plans/caisse-v1-ultra-finition/PHASE_F_SYNC_RESILIENCE_2026-04-26.md`
- `plans/caisse-v1-ultra-finition/PHASE_G_CLOSURE_PROOFS_2026-04-26.md`

## Recommendation

Do not start B+ implementation yet. Validate the plan, then execute Phase A only. After Phase A closes, create mission folders one task at a time from `TASK_REGISTRY_2026-04-26.md`.
