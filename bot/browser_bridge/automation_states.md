# Automation states — bridge layer vs supervisor FSM

## Supervisor FSM (source of truth)

States persisted in `bot/state/cycle_state.json` (existing bot):

- `idle`, `preparing_intake` (transient after `begin-cycle` wiring), `waiting_claude`, `waiting_cursor`, `waiting_validation`, `waiting_playwright`, `completed`, `blocked`, `manual_gate`

Sub-state: `claude_round` ∈ {`plan`, `review`} when `waiting_claude`.

---

## Bridge layer states (logical, derived — not a second FSM file)

The bridge **does not** persist its own FSM in v0. It derives a **logical** `action_kind` for runners:

| Supervisor state | `claude_round` | Bridge `action_kind` |
|------------------|----------------|----------------------|
| `waiting_claude` | `plan` | `claude_project_browser` |
| `waiting_claude` | `review` | `claude_project_browser` |
| `waiting_cursor` | * | `cursor_agent` |
| `waiting_validation` | * | `local_validation` |
| `waiting_playwright` | * | `playwright_pending` (human / separate runner) |
| `idle` / `completed` / `blocked` / `manual_gate` | * | `terminal_or_idle` |
| `session.paused` in session file | * | `paused` (overrides until cleared) |

---

## Optional future: persisted bridge_run state

If a long-running runner needs checkpoints **without** a daemon, use **one JSON per invocation**, e.g. `bot/state/browser_bridge_run_<timestamp>.json` written at start and end of a single Playwright process. Not implemented in v0.

---

## Mapping to `browser_bridge_next_action.json` fields

| Field | Meaning |
|-------|---------|
| `fsm_state` | Copy of supervisor state |
| `action_kind` | Derived row from table above |
| `actor` | `claude_browser` \| `cursor` \| `human` \| `human_or_ci` \| `none` |
| `instructions` | All paths absolute strings; booleans like `handoff_must_exist` |
| `blockers` | Missing files, pause, etc. |
| `supervisor_command` | Constant reminder for post-condition tick |

---

## Invariants

1. **Single writer** to `cycle_state.json`: existing `CycleController` / supervisor only — bridge never mutates it.
2. **Single consumer** of inbox JSON per successful path: supervisor `run_once`.
3. **Deterministic ordering:** Bridge resolution is pure function of `(cycle_state, supervisor_config, session)` — no randomness, no clock skew dependency beyond `updated_at` display.
