# Cursor — browser bridge playbook

> “Browser” here includes **Cursor IDE** automation in a future runner (Electron) or any supported remote UI. The bridge file format is the same.

## Target session

- **Not encoded in repo:** Set `cursor_session_label` in `bot/state/browser_bridge_session.json` (e.g. workspace name + agent tab title) for operator clarity.
- The bridge JSON echoes this as `session_hint` only — **no** Cursor API key or token.

## Outbox file (paste into Cursor Agent)

| FSM state | File under `bot/outbox/cursor/` |
|-----------|-----------------------------------|
| `waiting_cursor` | `cursor_handoff.md` |

Refresh via `run-supervisor-once` while in `waiting_cursor`.

## Inbox / runtime files

| FSM state | File | JSON `kind` |
|-----------|------|---------------|
| `waiting_cursor` | `bot/inbox/cursor_result/cursor_done.json` | `cursor_done` |
| `waiting_validation` | `bot/inbox/cursor_result/validation.json` | `validation_result` |

Both must carry the active `cycle_id`.

## Completion detection

- **`cursor_done`:** `kind == "cursor_done"`, `cycle_id` match, file archived after successful `run-supervisor-once`.
- **`validation_result`:** `kind == "validation_result"`, `status in {passed, failed, skipped}`, then supervisor advances FSM (`failed` → `blocked`).

## Blocked detection

- **Implementation incomplete:** Cursor should still emit `cursor_done.json` with factual `summary` and `files_changed`; orchestrator may return `NEEDS_FIX` or human sets `force-blocked`.
- **Cannot run tests:** Document in `reports/execution/latest.md`, put `validation.json` with `status: "failed"` only if operator accepts cycle block — otherwise use `manual_gate` via Claude review.

## Retry / resume

1. If `run-supervisor-once` rejects JSON, fix file, keep same `cycle_id`, retry tick.
2. If Cursor crashed mid-edit: restore files from git or handoff scope, re-run Agent with same `cursor_handoff.md` (after supervisor refreshes outbox).
3. **Resume:** Never start a new `begin-cycle` until terminal state or explicit `reset-idle` per operator policy.

## Ordering discipline

1. `cursor_done.json` **before** `validation.json`.
2. Do not place `validation.json` while still in `waiting_cursor` — supervisor will not consume it in that state.
