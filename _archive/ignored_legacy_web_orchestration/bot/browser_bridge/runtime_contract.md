# Browser bridge — runtime contract

## Purpose

Define **exact boundaries** between the file-based supervisor, Claude (browser), Cursor, and future Playwright so nothing “magically” becomes autonomous.

---

## What remains human-controlled (v1 bridge)

| Item | Why |
|------|-----|
| **Claude Project knowledge uploads** | Quality and compliance; not driven by repo files alone. |
| **Final GO / STOP on risky plans** | `human_decision`, `MANUAL_GATE`, frozen zones. |
| **Production secrets and `.env`** | Never read or written by the bridge. |
| **Cursor approval mode / network permissions** | Product security model is outside the bot. |
| **Playwright execution against real deployments** | Credentials, URLs, data — human-gated per `AGENTS.md`. |
| **Git push / PR merge** | Explicitly out of bot scope today. |

---

## What can be automated safely *next* (when a runner exists)

| Item | Guard |
|------|--------|
| **Read `browser_bridge_next_action.json`** | Deterministic input; no network until step is validated. |
| **Open known URL (Claude Project)** | Only after operator confirms account + project; session store may hold non-secret labels. |
| **Paste handoff file contents** | Source path is absolute path from JSON; verify file hash or mtime before paste. |
| **Extract assistant reply text** | Strip code fences; run structural JSON checks (`claude_browser_bridge.detect_malformed_claude_json`). |
| **Write `plan.json` / `review.json` to inbox** | Atomic write (temp + rename) to avoid partial JSON. |
| **Invoke `run-supervisor-once`** | Subprocess from runner **after** file flush; single tick, then exit. |
| **Cursor: paste `cursor_handoff.md`** | Same pattern; **do not** auto-approve dangerous file edits without policy extension. |

---

## What is still unsafe to fully automate (without new gates)

| Item | Risk |
|------|------|
| **Unbounded “fix and retry” loops** | Drift, partial JSON, wrong conversation. |
| **Auto-merging Claude/Cursor output into `app/`** | Violates review + scoring rubric. |
| **Running full `php artisan test` without sandbox** | Host pollution, accidental prod config. |
| **Silently switching Claude conversations** | Wrong plan attached to wrong cycle. |
| **Concurrent cycles** | Supervisor assumes one `cycle_id` active; duplicate inbox JSON risk. |

---

## Handoff boundaries (exact)

### Supervisor (existing Python)

- **Owns:** `cycle_state.json`, handoff folder under `bot/state/handoffs/<cycle_id>/`, inbox archive moves, outbox refresh on tick.
- **Consumes:** First `*.json` in plan/review inbox (sorted by name), `cursor_done.json`, `validation.json` in cursor inbox.
- **Does not:** Open browsers, call LLM APIs, interpret natural language beyond JSON validation already in code.

### Claude browser (future runner + today’s human)

- **Receives:** `instructions.paste_source_file` (Markdown handoff).
- **Produces:** JSON file in `inbox_claude_plan` or `inbox_claude_review` with correct `cycle_id` / `response_kind`.
- **Must not:** Invent cycle IDs; must target conversation **`00_ORCHESTRATOR`** unless session file overrides label (still human-approved).

### Cursor (IDE or future web runner)

- **Receives:** `cursor_handoff.md` content.
- **Produces:** `cursor_done.json`, then `validation.json` when FSM requires it.
- **Must not:** Write `cursor_done.json` when FSM is not `waiting_cursor` (supervisor ignores or errors depending on state).

### Playwright (later)

- **Owns:** `reports/antigravity/*`, UI evidence, screenshots.
- **Feeds:** `register-playwright-result` or human pastes into review cycle.
- **Does not:** Replace Claude orchestration verdict; complements it.

---

## Artifact flow (single tick discipline)

```
cycle_state -> bridge_controller -> browser_bridge_next_action.json
     |                                        |
     v                                        v
human or runner (browser)              inbox JSON files
     |                                        |
     v                                        v
           run-supervisor-once (one transition max)
```

No parallel daemons: each vertical step is **explicit** and **terminating**.
