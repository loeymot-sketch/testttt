# FoodKing — Claude Project Upload Order

> Exact sequence for uploading files to the Claude Project knowledge base.
> Operator must follow this order. Files higher in the list are read first by Claude.
> Last updated: 2026-04-12.

---

## How to Use This File

1. Open your Claude Project settings
2. Upload files in the exact order below (Phase 1 first, then Phase 2, etc.)
3. Files within the same phase can be uploaded together (order within phase is recommended but not critical)
4. After upload, verify Claude can reference key concepts by asking a test question

---

## Phase 1 — Operating Constitution (upload first)

These files define Claude's identity, rules, and operating principles.
Claude must absorb these before anything else.

| # | File | Why first |
|---|------|-----------|
| 1 | `CLAUDE.md` | Operating constitution — identity, principles, judgment framework |
| 2 | `AGENTS.md` | Workflow rules — agent roles, mandatory cycle, test vocabulary |
| 3 | `MEMORY.md` | Working memory — current state, risks, decisions, inspection queue |

**Size**: ~3 files, ~700 lines total

---

## Phase 2 — Orchestrator Intelligence (upload second)

These files give Claude FoodKing-specific operational intelligence.
They are the output of the deep repo audit and translate repo reality into judgment rules.

| # | File | Purpose |
|---|------|---------|
| 4 | `bot/onboarding/ORCHESTRATOR_STABLE_MEMORY.md` | Long-lived truths, real status enum, 4 order paths, 5 status paths, dangerous patterns, thinking routing |
| 5 | `bot/onboarding/ORCHESTRATOR_DECISION_RULES.md` | How to decide: approve, needs_fix, needs_playwright, blocked, manual_gate |
| 6 | `bot/onboarding/ORCHESTRATOR_REVIEW_GUARDRAILS.md` | Evidence classification, skepticism triggers, anti-complacency |
| 7 | `bot/onboarding/ORCHESTRATOR_SCOPE_RULES.md` | Cycle sizing, split rules, escalation triggers, anti-patterns |
| 8 | `bot/onboarding/ORCHESTRATOR_CYCLE_PRIORITIES.md` | Priority queue: what to inspect next, fix next, not touch yet |

**Size**: ~5 files, ~900 lines total

---

## Phase 3 — Project Architecture and Vision (upload third)

These files define the product vision, architecture, and business rules.
Claude needs these for domain understanding but should not memorize every detail — the stable memory already distills the critical parts.

| # | File | Purpose |
|---|------|---------|
| 9 | `docs/PROJECT_CONTINUITY_AND_VISION.md` | Product vision, deployment state, fix history, backlog |
| 10 | `docs/ARCHITECTURE.md` | System architecture, frozen zones, critical dependencies |
| 11 | `docs/BUSINESS_RULES.md` | SSOT pricing, ORDER_TOTAL formula, coupon rules, status pipeline |
| 12 | `docs/ORDER_FLOW.md` | Order lifecycle stages, actors per stage, forbidden transitions |
| 13 | `docs/AUTHZ_MATRIX.md` | Actor × permission matrix |
| 14 | `docs/DEVICE_FLOW.md` | Per-device behavior rules |

**Size**: ~6 files, ~500 lines total

---

## Phase 4 — Operational Contracts (upload fourth)

These files define how cycles work — intake format, output format, scoring, routing.

| # | File | Purpose |
|---|------|---------|
| 15 | `docs/ops/CLAUDE_CYCLE_INTAKE.md` | Intake package format — what Claude must receive each cycle |
| 16 | `docs/ops/CLAUDE_CYCLE_OUTPUT.md` | Output format — plan, verdict, clarification, playwright-brief |
| 17 | `docs/ops/CLAUDE_SCORING_RUBRIC.md` | 5-axis scoring, decision thresholds, FoodKing invariants |
| 18 | `docs/ops/BOT_TO_CLAUDE_RUNTIME_CONTRACT.md` | Stable knowledge vs runtime state separation |
| 19 | `docs/ops/CURSOR_MODEL_ROUTING_POLICY.md` | Execution class selection, model pinning |

**Size**: ~5 files, ~600 lines total

---

## Phase 5 — Role Specializations (upload fifth)

These files define Claude's role-switching capabilities for different cycle types.

| # | File | Purpose |
|---|------|---------|
| 20 | `docs/roles/00_ORCHESTRATOR_ROLE.md` | Cycle orchestration, intake→plan→verdict |
| 21 | `docs/roles/01_ARCHITECTURE_MEMORY_ROLE.md` | Structural impact analysis, blast radius |
| 22 | `docs/roles/02_PRODUCT_VISION_UX_ROLE.md` | Product/UX per surface |
| 23 | `docs/roles/03_DEEP_AUDIT_ROLE.md` | Deep QA audit, 8 dimensions |
| 24 | `docs/roles/04_RESEARCH_BENCHMARK_ROLE.md` | External research, compatibility analysis |

**Size**: ~5 files, ~400 lines total

---

## Phase 6 — Risk Intelligence (upload sixth)

These files provide deep risk knowledge. Upload after the judgment framework is in place.

| # | File | Purpose |
|---|------|---------|
| 25 | `bot/onboarding/PROJECT_ORCHESTRATOR_RISK_BRIEF.md` | 43 risks across 9 dimensions, with ORB IDs |
| 26 | `bot/onboarding/PROJECT_KNOWN_CRITICAL_PATHS.md` | 11 critical paths with evidence grades |
| 27 | `bot/onboarding/PROJECT_OPEN_RISKS.md` | 16 open risks, severity-ranked |

**Size**: ~3 files, ~800 lines total

---

## Phase 7 — Reference Intelligence (upload last)

These files provide structural inventory. Useful for reference but not needed for judgment.

| # | File | Purpose |
|---|------|---------|
| 28 | `bot/onboarding/PROJECT_ONBOARDING_SUMMARY.md` | Compact architecture brief, file counts, document hierarchy |
| 29 | `bot/onboarding/PROJECT_ONBOARDING_INDEX.md` | Full structural map — surfaces, services, models, routes, tests |
| 30 | `bot/onboarding/PROJECT_DOCSET_MANIFEST.json` | Machine-readable document manifest with conditional loading |

**Size**: ~3 files, ~600 lines total

---

## Phase 8 — Context Rules (upload with Phase 1)

This file tells Claude how to manage its own context across cycles. Upload alongside Phase 1.

| # | File | Purpose |
|---|------|---------|
| 31 | `bot/onboarding/CLAUDE_PROJECT_CYCLE_CONTEXT_RULES.md` | What to load per cycle, what to skip, how to avoid bloat |

---

## Do NOT Upload (runtime artifacts — injected per-cycle by bot/human)

These files change every cycle and must be injected fresh, not stored as project knowledge:

| File | Why not project knowledge |
|------|---------------------------|
| `reports/planning/latest.md` | Changes every cycle — must be injected fresh |
| `reports/execution/latest.md` | Changes every cycle — must be injected fresh |
| `reports/review/latest.md` | Changes every cycle — must be injected fresh |
| `reports/antigravity/latest.md` | Only relevant when Playwright runs |
| `reports/review/bugbot-latest.md` | Only relevant when Bugbot has findings |
| `bot/state/cycle_state.json` | Bot runtime state — not for Claude project |
| `bot/state/handoffs/*` | Bot handoff artifacts — not for Claude project |

---

## Do NOT Upload (supplementary docs — load on demand only)

These docs are useful but should not be in the permanent project knowledge to avoid context bloat:

| File | When to load |
|------|-------------|
| `docs/SECURITY_NOTES.md` | Only when cycle touches auth/security |
| `docs/CORE_MODULES.md` | Only when cycle touches architecture/refactor |
| `docs/DATABASE_SCHEMA_CORE.md` | Only when cycle touches migrations/schema |
| `docs/API_MAP.md` | Only when cycle touches API endpoints |
| `docs/TEST_PLAN.md` | Only when cycle touches test strategy |
| `docs/MASSIVE_TEST_PLAN.md` | Only when cycle touches comprehensive testing |
| `docs/PLAYWRIGHT_MCP_OPS.md` | Only when Playwright cycle is planned |
| `docs/SAAS_VISION.md` | Only when discussing multi-tenant evolution |
| `docs/GATES_DOCTRINE.md` | Only when implementing or reviewing gates |
| `docs/DECISION_GRAPHIFY.md` | Only when discussing memory architecture |
| `docs/QUEUE_WORKER_SETUP.md` | Only when cycle touches queue infrastructure |
| `docs/REALTIME_SETUP.md` | Only when cycle touches WebSocket/Pusher |
| `docs/FCM_SETUP.md` | Only when cycle touches Firebase push |

---

## Total Upload Budget

| Phase | Files | Estimated lines |
|-------|-------|-----------------|
| Phase 1 — Constitution | 3 | ~700 |
| Phase 2 — Orchestrator Intelligence | 5 | ~900 |
| Phase 3 — Architecture & Vision | 6 | ~500 |
| Phase 4 — Operational Contracts | 5 | ~600 |
| Phase 5 — Role Specializations | 5 | ~400 |
| Phase 6 — Risk Intelligence | 3 | ~800 |
| Phase 7 — Reference Intelligence | 3 | ~600 |
| Phase 8 — Context Rules | 1 | ~150 |
| **Total** | **31** | **~4,650** |

This is a complete onboarding pack. After these 31 files are uploaded, Claude has enough intelligence to begin autonomous orchestration without reading the entire repo every cycle.
