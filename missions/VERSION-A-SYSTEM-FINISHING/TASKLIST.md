# Version A System Finishing - Task List

Status: VERSION_A_SYSTEM_SOFTWARE_PASS_READY_FOR_HARDWARE_INDUSTRIAL_UAT
Plan: `plans/PLAN_VERSION_A_SYSTEM_FINISHING_2026-04-28.md`

## Global Rule

Hardware is deferred to final UAT. This task list finishes the software/system side first.

## Tracking

| ID | Mission | Priority | Status | Main Output |
| --- | --- | --- | --- | --- |
| VA-SYS-00 | Scope lock / hardware deferral | P0 | PASS_SCOPE_LOCK | Gate note + hardware deferral + strict API audit closeout |
| VA-SYS-01 | Dashboard workflow discovery | P1 | PASS_DISCOVERY_WITH_SELECTOR_REWORK_FOR_VA_SYS_04 | Discovery report + selector/helper map; stable test hooks required before VA-SYS-05 |
| VA-SYS-02 | Composer request contract hardening | P1 | PASS_LOCAL_STRONG | Fixed source closed + service/request/publish/pricing guards + Composer/Menu/JS tests |
| VA-SYS-03 | Wizard runtime contract | P1 | PASS_LOCAL | no-wizard simple lock + legacy heuristic fallback + runtime/pricing tests |
| VA-SYS-04 | Dashboard builder UX hardening | P1/P2 | PASS_LOCAL | Stable dashboard hooks for product/category/photo/modifiers/availability/composer + production build |
| VA-SYS-05 | Full dashboard-to-kiosk/POS/KDS E2E | P1 high | PASS_RUNTIME_LOCAL_STRONG | Central product/category/composer projection + POS order + KDS + stock/history Playwright 3/3, C3 4/4 |
| VA-SYS-06 | Stockable choices semantics | P1 | PASS_LOCAL | Product rupture + choice-level stock implementation + projection/pricing/POS-kiosk tests |
| VA-SYS-07 | Central management authz matrix | P1 | PASS_LOCAL_STRONG | Dashboard branch scope + composer show scope + availability fanout + global photo authz + seeder wiring tests |
| VA-SYS-08 | Realtime/outbox production-like simulation | P1 | PASS_RUNTIME_LOCAL_STRONG | Outbox production-like simulation + JS reconnect contracts + C3 multi-surface Playwright repeat |
| VA-SYS-09 | Docs/runbook/memory close | P2 | PASS_DOCS_MEMORY | `docs/sync/*` + memory update + final validation matrix |
| VA-SYS-10 | Final massive validation | P0 close | PASS_FINAL_SOFTWARE_CLOSE_POST_VA_SYS_05_RERUN | Core validation pack rerun after VA-SYS-05 + MySQL surface filtering PASS + immutable runtime artifacts; hardware/provider UAT remains deferred |

## Default Decisions Proposed By Codex

- Hardware: deferred to final UAT.
- Runtime protocol: API + WebSocket/outbox, not MCP.
- Wizard: no-migration hardening first; `step_kind/ui_component` migration only with explicit validation.
- Dashboard E2E: mandatory before calling Version A management complete.
- Stock: user confirmed product rupture + choice-level rupture for stockable wizard choices.
- Authz matrix: mandatory before multi-branch production.

## Execution Protocol After Validation

For every mission:

1. Pre-audit.
2. Implement bounded changes.
3. Run focused tests.
4. Run adversarial read-only review.
5. Fix REWORK.
6. Run run-many validation.
7. Write mission report.
8. Move forward only on PASS.

## Final Target

`VERSION_A_SYSTEM_SOFTWARE: PASS_READY_FOR_HARDWARE_INDUSTRIAL_UAT`
