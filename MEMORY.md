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

---

## 6. Current Open Questions

- exact final Claude cycle JSON format
- exact scoring thresholds and rubric
- exact intake package for Claude each cycle
- exact output package for Cursor each cycle in real use
- timing and method for implementing the bot runtime
- future integration timing for Graphify POC

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

---

## 9. Continuity Notes

When restarting work:
1. read CLAUDE.md
2. read this file
3. read the latest relevant report files
4. inspect only the documents needed for the active cycle
5. do not reload unnecessary history

The system must preserve clarity and avoid bloated context.

---

## 10. Maintenance Rule

This file must remain:
- compact
- high-signal
- current
- non-redundant

If this file becomes noisy, duplicated, outdated, or too long, compress it before continuing.