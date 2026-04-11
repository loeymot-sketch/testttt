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

### Current System Pattern
- Claude = orchestrator / judge / planner
- Cursor = strict executor
- Playwright = behavioral verifier
- Bot/pipeline = state transport and synchronization layer

### Current Status
- Cursor system is prepared and structured
- project rules are in place
- Playwright MCP is validated
- Claude project is being configured
- full runtime bot pipeline is not yet implemented

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

- bot pipeline runtime not yet implemented
- full Claude orchestrator prompt not yet installed
- future gates not yet implemented as executable components
- Graphify not yet tested through isolated POC
- Graphiti intentionally postponed
- no full end-to-end Claude → Cursor → Playwright → Claude production cycle has run yet
- [RISK-NEW] OrderService::changeStatus — $auth===false
  branch dispatches SendOrderMail/Sms/Push at L1286–1288
  BEFORE $order->save() at L1290. No DB::transaction in
  method. Notification can fire for a status change that
  fails to persist. Severity: MAJOR. Target: CYCLE-002.

- [RISK-NEW] OrderService::changeStatus — $auth===true
  (customer) branch has NO OrderStatusChanged::dispatch.
  OSS and KDS will not receive broadcast for customer-
  triggered status changes. Reachability in production
  unconfirmed. Must be classified before any changeStatus
  change is approved.

- [RISK-NEW] BroadcastableOrder class uninspected.
  Used in OrderStatusChanged constructor. If it
  eager-loads relationships, a missing relationship
  silently corrupts the broadcast payload. Required
  inspection before any OrderStatusChanged change.

- [RISK-NEW] ShouldBroadcastNow exception propagation
  unverified. OrderCreated and OrderStatusChanged use
  synchronous broadcast. If Pusher/Soketi is unavailable,
  the exception impact on the HTTP request is unknown.

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
- reports/antigravity/latest.md
- reports/review/latest.md

---

## 8. Recent Important Decisions

- The system will use Claude as the single central orchestrator and judge
- Cursor remains the strict executor and must not become the strategist
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