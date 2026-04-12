# Orchestrator intake (Claude) — template

**Cycle / task id:** `{{TASK_ID}}`  
**Trigger source:** `{{TRIGGER_SOURCE}}`  
**Human goal (verbatim):**  
{{HUMAN_GOAL}}

## Packaged context (paths or summaries)

- Planning: `{{PLANNING_PATH}}`
- Continuity / vision pointers: `{{CONTINUITY_PATHS}}`
- Prior execution snippet (if any): `{{EXECUTION_SNIPPET}}`

## Required output shape (for the bot to parse)

1. **Objective** — one paragraph.  
2. **Scope / non-goals** — bullet list.  
3. **Risk class** — e.g. authz, pricing, order state, UI-only, docs-only.  
4. **Suggested next actor** — `claude_plan` | `cursor_execute` | `human_gate` | `playwright`.  
5. **Test stance** — Kimi-test | Anti-Gravity | No-test (per `AGENTS.md`).

_Implementation note: the runtime will substitute `{{…}}` placeholders; unused keys may be omitted._
