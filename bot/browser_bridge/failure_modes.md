# Browser bridge — failure modes catalog

Each row: **symptom** → **detection** → **mitigation** (deterministic, no hidden autonomy).

---

## 1. Claude returns non-JSON

- **Detection:** `detect_malformed_claude_json` or supervisor raises `JSONDecodeError` / wrong shape.
- **Mitigation:** Strip markdown fences; save raw assistant text to `bot/logs/` manually if needed; fix file; remove bad inbox file; retry `run-supervisor-once`.

## 2. Claude quota hit / interruption

- **Detection:** UI error message; partial stream; no new assistant message.
- **Mitigation:** Set `browser_bridge_session.json` `paused=true`, `pause_reason=claude_quota`; rerun bridge-next-action; resume only after human confirms quota reset.

## 3. Wrong conversation targeted

- **Detection:** Plan JSON references wrong `task_id` / context; or assistant reply unrelated to handoff.
- **Mitigation:** Verify sidebar `00_ORCHESTRATOR`; delete wrong inbox JSON; re-paste from current `paste_source_file` path in `browser_bridge_next_action.json`.

## 4. Stale outbox file

- **Detection:** `handoff_must_exist: false` in next-action JSON, or `mtime` older than `cycle_state.updated_at` (future runner check).
- **Mitigation:** Run `run-supervisor-once` to refresh outbox from `handoffs/<cycle_id>/` before paste.

## 5. Stale inbox file

- **Detection:** Archived JSON from **previous** `cycle_id` still present in working copy (should not — only one active cycle).
- **Mitigation:** Ensure archive moved; grep inbox for `cycle_id`; delete stray files.

## 6. Cursor writes runtime JSON in wrong FSM state

- **Detection:** `validation.json` appears while `waiting_cursor` — supervisor ignores for validation step; or `cursor_done` during `waiting_claude`.
- **Mitigation:** Remove file; align FSM via `show-state`; re-run supervisor only in valid state.

## 7. Supervisor waiting on wrong phase

- **Detection:** e.g. `cycle_state.state=waiting_claude` but operator pasted Cursor handoff only.
- **Mitigation:** Read `browser_bridge_next_action.json`; follow `action_kind`; never skip Claude plan when FSM requires it.

## 8. Duplicate consumption risk

- **Detection:** Two `plan.json`-like files; first by sorted name wins — may be wrong file.
- **Mitigation:** Keep **exactly one** eligible `*.json` per inbox per tick; archive extras before tick.

## 9. Browser session lost

- **Detection:** Runner cannot find DOM nodes; auth redirect to login.
- **Mitigation:** Pause bridge session; human re-auth; update `selectors.local.json`; restart **single** runner invocation.

## 10. Model switch / manual approval interruption

- **Detection:** Cursor or Claude UI prompts for approval mid-step.
- **Mitigation:** Human completes approval; runner exits with `pause_reason=manual_ui_gate`; bridge does not auto-click “Approve”.

---

## Escalation shorthand

| Code | Meaning |
|------|---------|
| `non_json` | Assistant body not parseable |
| `wrong_cycle` | `cycle_id` mismatch |
| `ambiguous_inbox` | Too many JSON candidates |
| `browser_session_lost` | Auth or DOM session |
| `wrong_fsm` | File dropped for wrong phase |

Store optional `last_error_code` in `browser_bridge_session.json` from future runner (not required v0).
