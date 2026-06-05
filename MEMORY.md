# MEMORY.md — FoodKing Working Memory Index

## 1. Purpose

This file is the compact working memory index for FoodKing.

It is not the place for full explanations, long history, or raw dumps.
It exists to help Claude and the system quickly recover the current state of the project without rereading everything.

This file should stay compact, useful, and current.

---

## 2. How To Use This File

Use this file to track:
- active priorities
- current risks
- recent important decisions
- open questions
- routing pointers to deeper documents
- current cycle continuity notes

Do not use this file as a replacement for:
- CLAUDE.md
- full architecture documentation
- full business rules
- detailed reports
- raw execution history

---

## 3. Current Project State

### Project
FoodKing — restaurant SaaS platform

### Main Surfaces
- POS
- kiosk
- KDS
- OSS
- branch operations
- ordering flows

### Current System Pattern (cloud-as-supervisor, since 2026-06-05)
- Cloud Claude Code = supervisor **+** executor (plans, implements, validates, audits, judges)
- Sub-agents = Explore (read-only search), general-purpose Task (isolated verification)
- Playwright = behavioral verifier
- Human = final authority / merge gate
- (Retired: Cursor-as-executor, Cowork, state-transport bot.) Detail → `docs/orchestration/AGENT_ROLES.md`

### Current Status
- All audit global cycles CLOSED: REALTIME_001, PAYMENT_SAFETY_001, KIOSK_RELIABILITY_001, PRODUCTION_READY_001
- 196 PHPUnit tests passing, npm run prod 0 errors
- 18 findings (4 CRITICAL, 6 MAJOR, 8 MINOR) all addressed
- Ready for final Playwright E2E validation + human gate production

---

## 4. Stable Priorities

- protect architecture coherence
- preserve backend source of truth for pricing
- preserve strict branch isolation
- preserve correct order status transitions
- preserve coherence across POS, kiosk, KDS, OSS
- maintain strong UX quality
- require real validation evidence
- reduce token waste without losing intelligence

---

## 5. Current Open Risks

- cloud SessionStart hook + `.claude/settings.json` proposed but not yet committed (auto-mode classifier blocked creation — needs explicit user permission)
- production GO still gated: POS-9.2 / POS-9.3 `pending`; browser/device Anti-Gravity E2E never run; monolithic `php artisan test` memory-bound (use batch pipeline)
- future gates not yet implemented as executable components
- Graphiti intentionally postponed
- [RISK-NEW] OrderService::changeStatus — $auth===false
  branch dispatches SendOrderMail/Sms/Push at L1286–1288
  BEFORE $order->save() at L1290. No DB::transaction in
  method. Notification can fire for a status change that
  fails to persist. Severity: MAJOR. Target: CYCLE-002.

- [RISK-CLEARED] OrderService::changeStatus — $auth===true
  Inspected: OrderStatusChanged::dispatch IS present (L.1292).
  Dispatch after save() confirmed. No gap.

- [RISK-CLEARED] BroadcastableOrder class.
  Inspected: interface is empty (marker only), no eager loading,
  no relationship risk. Safe.

- [RISK-CLEARED] ShouldBroadcastNow exception propagation.
  Inspected: all OrderStatusChanged::dispatch calls are wrapped
  in try/catch in both OrderService and FrontendOrderService.
  Exception is logged, never propagated to HTTP response.

---

## 6. Current Open Questions

- exact final Claude cycle JSON format
- exact scoring thresholds and rubric
- exact intake package for Claude each cycle
- exact output package for Cursor each cycle in real use
- timing and method for implementing the bot runtime
- future integration timing for Graphify POC
- Is the pre-save notification dispatch in
  OrderService::changeStatus ($auth===false) intentional
  (performance / legacy pattern) or an unaddressed
  regression? Human judgment required before CYCLE-002
  fix plan can be written.

- Is the $auth===true branch in OrderService::changeStatus
  reachable in production? If yes, the absence of
  OrderStatusChanged::dispatch is a silent OSS/KDS
  coherence gap.

---

## 7. Routing Pointers

### Governance and Vision
- CLAUDE.md
- AGENTS.md
- docs/PROJECT_CONTINUITY_AND_VISION.md
- docs/ARCHITECTURE.md

### Business and Product Logic
- docs/BUSINESS_RULES.md
- docs/ORDER_FLOW.md
- docs/DEVICE_FLOW.md
- docs/API_MAP.md
- docs/AUTHZ_MATRIX.md

### Testing and QA
- docs/PLAYWRIGHT_MCP_OPS.md
- docs/TEST_PLAN.md
- docs/MASSIVE_TEST_PLAN.md

### Future Control Gates
- docs/GATES_DOCTRINE.md

### Graphify Decision
- docs/DECISION_GRAPHIFY.md

### Operational Reports
- reports/planning/latest.md
- reports/execution/latest.md
- Playwright / E2E cycle report: reports/antigravity/latest.md
- reports/review/latest.md

---

### Permissions Audit (S3 / F-16)
No wildcard `*` permission found in Spatie roles. Admin role uses `givePermissionTo(Permission::all())` — all named permissions, not a catch-all. No action required.

## 8. Recent Important Decisions

- **2026-06-05 — Migrated to cloud-as-supervisor.** Claude Code on the web is the sole supervisor **and**
  executor; Cursor/Cowork and the state-transport bot are retired. Governance rewritten in place
  (`CLAUDE.md` §4, `AGENTS.md`, `docs/orchestration/AGENT_ROLES.md`, bootstrap). Obsolete prohibitions
  (never edit `CLAUDE.md`, never create `.claude/` skills) lifted.
- The system uses Claude as the single central supervisor, judge, and executor
- Playwright MCP is active and validated for local interactive verification
- Multi-conversation Claude architecture is preferred over flat swarms
- Memory will start simple and disciplined before adding heavier graph-based memory
- Graphify is considered promising but will only enter through a controlled POC
- Graphiti is postponed until real cycles prove the need for temporal memory
- Audit/scoring will be mandatory in the Claude loop
- CYCLE-001 APPROVED (2026-04-11, high confidence,
  global score 89). INV-05 baseline established from
  direct code inspection. All four corrections #1–#4
  confirmed holding in current codebase.

- INV-05 BASELINE locked:
    OrderService::myOrderStore     tx L256–467, dispatch after ✓
    OrderService::tableOrderStore  tx L864–1069, dispatch after ✓
    OrderService::posOrderStore    tx L508–820, dispatch after ✓
                                   OrderCreated at L829 ✓
    OrderService::changeStatus     no tx; OrderStatusChanged
                                   at L1293–1294 after save() ✓
                                   (admin/$auth===false path only)
    KDSOrderService::changeStatus  tx L115–118, dispatch after ✓
                                   OrderStatusChanged at L127 ✓

- SendOrderGotPush lives in app/Events/ not app/Jobs/.
  All documentation references to app/Jobs/SendOrderGotPush
  are stale. Code is correct; docs need update.

- Model routing policy locked: every executable plan
  must include execution class, preferred model,
  fallback, reason, and max mode.

- First Claude→Cursor→Claude pipeline cycle completed
  and validated. Orchestration pipeline is operational.

---

## 9. Inspection Priority Queue

Next inspections in order. Do not skip or reorder
without orchestrator decision.

1. OrderService::changeStatus — classify no-transaction
   pattern and pre-save dispatch. Requires human answer
   on intent before fix plan. → CYCLE-002
2. BroadcastableOrder — inspect constructor and
   relationship loading. Required before any
   OrderStatusChanged change is approved.
3. FrontendOrderService — full INV-05 equivalent
   inspection. Verify corrections #8 and #9.
   Verify FrontendOrder.$fillable. → CYCLE-003
4. Auth after refresh — inspect /api/auth/authcheck
   response. Confirm landing_url in defaultPermission.
5. CouponService — inspect MaxDiscount cap and
   ORDER_TOTAL floor. Required before any pricing cycle.

---

## 10. Continuity Notes

When restarting work:
1. read CLAUDE.md
2. read this file
3. read the latest relevant report files
4. inspect only the documents needed for the active cycle
5. do not reload unnecessary history

The system must preserve clarity and avoid bloated context.

---

## 11. Maintenance Rule

This file must remain:
- compact
- high-signal
- current
- non-redundant

If this file becomes noisy, duplicated, outdated, or too long, compress it before continuing.