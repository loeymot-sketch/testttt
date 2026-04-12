# Claude Project — browser bridge playbook

## Target conversation

- **Default label:** `00_ORCHESTRATOR` (FoodKing orchestrator role in Claude Project).
- **Override:** `claude_conversation_label` in `bot/state/browser_bridge_session.json` (operator sets; bridge echoes in `browser_bridge_next_action.json`).

## Outbox files (source of paste)

| FSM | `claude_round` | File under `bot/outbox/claude/` |
|-----|----------------|----------------------------------|
| `waiting_claude` | `plan` | `claude_handoff.md` |
| `waiting_claude` | `review` | `claude_review_handoff.md` |

**Precondition:** Run `run-supervisor-once` once so the outbox copy is refreshed from `bot/state/handoffs/<cycle_id>/`.

## Inbox files (expected drop)

| Phase | Directory | Suggested filename | Required JSON |
|-------|-----------|-------------------|---------------|
| Plan | `bot/inbox/claude_plan/` | `plan.json` (any `*.json` works; first by name order) | `response_kind: "plan"`, `verdict: null`, matching `cycle_id` / `task_id` |
| Review | `bot/inbox/claude_review/` | `review.json` | `response_kind: "review"`, `verdict` in approved set |

## Success detection

1. File appears with extension `.json` (not `.example.json`).
2. `json.loads` succeeds on file body (after stripping UTF-8 BOM if present).
3. `cycle_id` equals active `cycle_state.json` `cycle_id`.
4. `response_kind` matches phase (`plan` vs `review`).
5. Plan: `verdict is null`. Review: `verdict` is one of `APPROVED`, `NEEDS_FIX`, `NEEDS_PLAYWRIGHT`, `BLOCKED`, `MANUAL_GATE`.
6. Operator runs `run-supervisor-once` — stdout shows `registered plan` or `registered review`, and file is **archived** under handoff folder.

## Malformed output detection

| Symptom | Classification |
|---------|----------------|
| Prose only, no JSON | `non_json` |
| JSON inside ` ```json ` fences saved literally | `fenced_markdown` |
| Valid JSON but `response_kind` wrong | `wrong_kind` |
| `cycle_id` mismatch | `wrong_cycle` |
| `task_id` mismatch when state has `task_id` | `wrong_task` |
| Multiple competing `*.json` files | `ambiguous_inbox` — supervisor picks first by name; may ingest wrong file |

Use `claude_browser_bridge.detect_malformed_claude_json` for a quick local check before dropping (optional helper).

## Retry policy

1. **Never** leave two unconsumed JSON files in the same inbox for the same cycle (archive or delete stray files).
2. Fix content → save → `run-supervisor-once` again.
3. Max retries: operational policy (e.g. 3) then `force-blocked` or human conversation reset — **not** hard-coded in bridge v0.

## Quota / session interruption

| Event | Surface in bridge |
|-------|-------------------|
| Claude rate limit / quota | Runner sets `session.paused=true`, `pause_reason=claude_quota` in `browser_bridge_session.json`; `browser-bridge-next-action` returns `action_kind: paused`. |
| Browser tab closed | `last_error_code=browser_session_lost` in session file; human restores. |
| Wrong project | `wrong_conversation` — human verifies sidebar title matches label. |

No auto-retry on quota without human acknowledgment.
