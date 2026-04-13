# FoodKing — Browser bridge (automation-ready)

This package **does not** run a browser, **does not** call Claude or Cursor APIs, and **does not** start a daemon.

It provides:

1. **Deterministic resolution** of the next human or automation step from `bot/state/cycle_state.json` + `bot/config/supervisor.json` + optional `bot/state/browser_bridge_session.json`.
2. **JSON artifacts** written to `bot/state/browser_bridge_next_action.json` for a future Playwright/MCP runner or operator script.
3. **Playbooks and contracts** (Markdown) so automation never pretends to be the orchestrator.

## Commands (CLI)

From repo root (`PYTHONPATH=.` or `.\bot-cli.ps1`):

| Command | Purpose |
|---------|---------|
| `browser-bridge-status` | Print session + cycle state + key paths (JSON). |
| `browser-bridge-prepare` | Sync session `last_cycle_id`, write `browser_bridge_next_action.json`, print its path. |
| `browser-bridge-next-action` | Print and write the same next-action JSON (deterministic). |

## Layout

| Path | Role |
|------|------|
| `bridge_controller.py` | Maps FSM to `action_kind` + `instructions` + `blockers`. |
| `claude_browser_bridge.py` | Claude Project: handoff files, inbox dirs, JSON heuristics. |
| `cursor_browser_bridge.py` | Cursor: `cursor_handoff.md`, `cursor_done.json`, `validation.json`. |
| `session_store.py` | Optional pause flags and conversation/session labels. |
| `runtime_contract.md` | Human vs safe-auto vs unsafe boundaries. |
| `*_playbook.md` | Operator + future runner procedures. |
| `failure_modes.md` | Recovery catalog. |
| `automation_states.md` | Bridge layer state model vs supervisor FSM. |
| `selectors.example.json` | **Example only** — DOM selectors for a future runner (edit a non-committed copy or `selectors.local.json` if you add one). |

## Integration with supervisor

After the browser or Cursor produces the expected file in the correct inbox:

```text
python bot/cli.py run-supervisor-once
```

The bridge **never** replaces that tick; it only tells you **what** to do before the tick.

## Next implementation phase (explicitly out of scope here)

- A **short-lived** Playwright (or MCP) process that reads `browser_bridge_next_action.json`, performs UI steps, exits.
- Selector file promoted from `.example` to operator-managed secret path if needed.
- No `while true` loops, no Telegram, no headless “ghost” sessions.
