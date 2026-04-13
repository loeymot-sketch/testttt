# FoodKing — Claude Project Cycle Context Rules

> How to manage Claude's context across cycles without bloating or losing intelligence.
> For both human operators and the bot pipeline.
> Complements: `docs/ops/BOT_TO_CLAUDE_RUNTIME_CONTRACT.md` (transport contract).

---

## 1. The Three Context Layers

| Layer | What it contains | Lifecycle | Where it lives |
|-------|-----------------|-----------|----------------|
| **Permanent knowledge** | Project truths, rules, architecture, risks | Changes only on explicit doc updates | Claude Project knowledge base (uploaded files) |
| **Cycle context** | Current plan, execution report, review, diff | Changes every cycle — injected fresh | Bot payload / human paste into conversation |
| **Ephemeral context** | Conversation history, intermediate reasoning | Dies when conversation ends | Chat thread only |

### The golden rule

**Permanent knowledge is uploaded once. Cycle context is injected every time. Ephemeral context is never relied upon.**

---

## 2. What Belongs in One-Time Onboarding (Permanent Knowledge)

Upload these to the Claude Project **once** and only update them when the underlying repo docs change.

### Constitution (always loaded by Claude)
| File | Update trigger |
|------|----------------|
| `CLAUDE.md` | When operating principles change |
| `AGENTS.md` | When workflow rules change |
| `MEMORY.md` | After each significant cycle (risks, decisions, inspection queue) |

### Orchestrator intelligence (always loaded)
| File | Update trigger |
|------|----------------|
| `ORCHESTRATOR_STABLE_MEMORY.md` | When new truths are discovered or old ones invalidated |
| `ORCHESTRATOR_DECISION_RULES.md` | When verdict logic changes |
| `ORCHESTRATOR_REVIEW_GUARDRAILS.md` | When evidence standards change |
| `ORCHESTRATOR_SCOPE_RULES.md` | When cycle sizing rules change |
| `ORCHESTRATOR_CYCLE_PRIORITIES.md` | After each completed tier-1 or tier-2 item |

### Architecture and vision (loaded, referenced as needed)
| File | Update trigger |
|------|----------------|
| `docs/PROJECT_CONTINUITY_AND_VISION.md` | When vision or backlog changes |
| `docs/ARCHITECTURE.md` | When architecture or frozen zones change |
| `docs/BUSINESS_RULES.md` | When pricing, coupon, or status rules change |
| `docs/ORDER_FLOW.md` | When order lifecycle changes |
| `docs/AUTHZ_MATRIX.md` | When actor permissions change |
| `docs/DEVICE_FLOW.md` | When device behavior changes |

### Operational contracts (loaded, referenced as needed)
| File | Update trigger |
|------|----------------|
| `docs/ops/CLAUDE_CYCLE_INTAKE.md` | When intake format changes |
| `docs/ops/CLAUDE_CYCLE_OUTPUT.md` | When output format changes |
| `docs/ops/CLAUDE_SCORING_RUBRIC.md` | When scoring thresholds change |
| `docs/ops/BOT_TO_CLAUDE_RUNTIME_CONTRACT.md` | When transport contract changes |
| `docs/ops/CURSOR_MODEL_ROUTING_POLICY.md` | When model routing changes |

### Risk intelligence (loaded, referenced on inspection cycles)
| File | Update trigger |
|------|----------------|
| `PROJECT_ORCHESTRATOR_RISK_BRIEF.md` | When risks are resolved or new ones discovered |
| `PROJECT_KNOWN_CRITICAL_PATHS.md` | When critical paths change |
| `PROJECT_OPEN_RISKS.md` | When risks are resolved or re-ranked |

---

## 3. What Belongs in Per-Cycle Handoffs (Cycle Context)

Inject these fresh at the start of every cycle. Never rely on project knowledge for these — they are runtime state.

### Always inject (every cycle)

| File | Purpose |
|------|---------|
| **Intake package** | Structured intake per `CLAUDE_CYCLE_INTAKE.md` format — the cycle's mission |
| `reports/planning/latest.md` | Active plan (if reviewing execution or post-Playwright) |
| `reports/execution/latest.md` | Execution results (if reviewing) |
| `reports/review/latest.md` | Previous verdict (if resuming or re-reviewing) |

### Inject when applicable

| File | When |
|------|------|
| `reports/antigravity/latest.md` | After Playwright cycle |
| `reports/review/bugbot-latest.md` | When Bugbot findings exist |
| Diff summary / changed files list | When bot generates it |

### Injection order (most important first)

Per `BOT_TO_CLAUDE_RUNTIME_CONTRACT.md` §5:

1. Diff summary / files touched (if available)
2. `reports/review/latest.md` (previous verdict)
3. `reports/execution/latest.md` (execution evidence)
4. `reports/planning/latest.md` (active plan)
5. Intake package
6. `MEMORY.md` (stable state — but already in project knowledge, so only inject if updated since last upload)

---

## 4. What Should NOT Be Resent Every Cycle

### Never inject as cycle context

| File | Why not |
|------|---------|
| `CLAUDE.md` | Already in project knowledge — Claude reads it automatically |
| `AGENTS.md` | Already in project knowledge |
| `docs/ARCHITECTURE.md` | Already in project knowledge — reference by pointer, not re-injection |
| `docs/BUSINESS_RULES.md` | Same — pointer only |
| Any `bot/onboarding/*.md` | Already in project knowledge |
| Any `docs/roles/*.md` | Already in project knowledge |
| Any `docs/ops/*.md` | Already in project knowledge |

### Why this matters

- Each re-injection costs context tokens and dilutes the cycle-specific signal
- Claude's project knowledge is always available — re-injecting it is redundant
- The intake format already includes `required_docs` field — Claude can look up specific docs when the intake tells it to

### The pointer rule

Instead of re-injecting `docs/BUSINESS_RULES.md`, the intake should say:

```
Required docs:
- docs/BUSINESS_RULES.md (pricing SSOT, coupon rules)
```

Claude will read it from project knowledge. No re-injection needed.

---

## 5. How to Avoid Context Bloat

### Rule 1: Separate truth layers

| Question | Answer |
|----------|--------|
| "Does Claude need to know this every cycle?" | → Permanent knowledge (upload once) |
| "Does Claude need to know this for THIS cycle?" | → Cycle context (inject fresh) |
| "Does Claude need to know this at all?" | → Maybe not — don't inject |

### Rule 2: Compress cycle context

The intake package should be **compact** (~50–100 lines). It should contain:
- What to do (objective, question)
- What's affected (surfaces, critical zones, known paths)
- What evidence exists (reports, tests)
- What's out of scope

It should NOT contain:
- Full architecture explanation (already in project knowledge)
- Full business rules (already in project knowledge)
- History of previous cycles (already in `MEMORY.md`)
- Copy-pasted doc sections (use pointers)

### Rule 3: Don't inject supplementary docs proactively

The following docs should only be injected when the cycle's `critical_zones` or `required_docs` explicitly need them:

| Doc | Inject when |
|-----|-------------|
| `docs/SECURITY_NOTES.md` | `critical_zones: auth` |
| `docs/CORE_MODULES.md` | `critical_zones: architecture` |
| `docs/DATABASE_SCHEMA_CORE.md` | Cycle touches migrations |
| `docs/API_MAP.md` | Cycle touches API routes |
| `docs/PLAYWRIGHT_MCP_OPS.md` | Cycle type is `playwright-*` |
| `docs/GATES_DOCTRINE.md` | Cycle touches gate implementation |
| `docs/QUEUE_WORKER_SETUP.md` | Cycle touches queue infrastructure |

### Rule 4: Trust MEMORY.md for continuity

`MEMORY.md` exists to prevent Claude from needing full history. It contains:
- Current priorities
- Open risks (with ORB IDs linking to the risk brief)
- Recent decisions
- Inspection queue order
- Open human questions

If `MEMORY.md` is up to date, Claude should not need prior conversation history to make decisions.

### Rule 5: Update MEMORY.md after significant cycles

After each cycle that reveals a new risk, closes a question, or changes priorities, the operator (or bot) should update `MEMORY.md` and re-upload it to project knowledge. This keeps the permanent layer fresh without re-injecting everything.

---

## 6. Context Budget Guidelines

### Permanent knowledge budget (project files)

| Category | Target | Current |
|----------|--------|---------|
| Constitution | ~700 lines | 3 files |
| Orchestrator intelligence | ~900 lines | 5 files |
| Architecture/vision | ~500 lines | 6 files |
| Operational contracts | ~600 lines | 5 files |
| Role specializations | ~400 lines | 5 files |
| Risk intelligence | ~800 lines | 3 files |
| Reference | ~600 lines | 3 files |
| Context rules | ~150 lines | 1 file |
| **Total permanent** | **~4,650 lines** | **31 files** |

### Per-cycle injection budget

| Component | Target | Notes |
|-----------|--------|-------|
| Intake package | 50–100 lines | Structured, compact |
| reports/planning/latest.md | 50–150 lines | Plan with tasks and DoD |
| reports/execution/latest.md | 50–200 lines | Results with evidence |
| reports/review/latest.md | 30–80 lines | Scoring + verdict |
| Supplementary doc (if needed) | 50–200 lines | At most 1–2 per cycle |
| **Total per-cycle** | **~200–600 lines** | Should never exceed 800 |

### Bloat warning signs

| Sign | Action |
|------|--------|
| Per-cycle injection > 800 lines | Reduce — compress intake, summarize reports |
| Same supplementary doc injected 3+ cycles in a row | Consider uploading it to permanent knowledge |
| `MEMORY.md` > 300 lines | Compress — move resolved items to archive |
| Intake has copy-pasted doc sections | Replace with pointers |
| Multiple report files injected that don't relate to the current cycle | Remove — only inject what's relevant |

---

## 7. Operator Checklist: Before Each Cycle

1. [ ] Is the intake package written per `CLAUDE_CYCLE_INTAKE.md` format?
2. [ ] Are `reports/*/latest.md` files current for this cycle?
3. [ ] Is `MEMORY.md` up to date? (If not, update and re-upload to project knowledge)
4. [ ] Am I only injecting cycle-specific files? (Not re-injecting permanent knowledge)
5. [ ] Is the per-cycle payload under 800 lines?
6. [ ] Did I specify `required_docs` as pointers, not full re-injections?

---

## 8. Operator Checklist: After Each Significant Cycle

1. [ ] Does `MEMORY.md` need updating? (New risk, closed question, new decision)
2. [ ] Does `ORCHESTRATOR_CYCLE_PRIORITIES.md` need re-ranking? (Completed item, new priority)
3. [ ] Does any permanent knowledge file need correction? (Doc/code mismatch found)
4. [ ] If any file was updated, re-upload it to Claude Project knowledge
5. [ ] Archive cycle-specific reports (they'll be overwritten by next cycle)
