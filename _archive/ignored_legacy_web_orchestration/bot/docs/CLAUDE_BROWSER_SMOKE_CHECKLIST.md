# Claude browser — smoke checklist (dedicated machine)

Stable setup: **Chromium persistent profile** under the repo — `bot/browser_runner/profiles/claude-user-data` — not your main Chrome profile. Cursor stays **`clipboard`** (`browser_profiles.json` → `cursor.automation_mode`).

## Config to verify once

- `bot/browser_runner/browser_profiles.json` exists (copy from `browser_profiles.example.json` if needed).
- **`claude.start_url`** = direct orchestrator chat URL (`https://claude.ai/chat/<uuid>`).
- **`claude.expected_chat_url`** = **same** URL as `start_url` (strict URL-first guard).
- **`claude.orchestrator_conversation_label`** = `00_ORCHESTRATOR` (fallback if URL check cannot run).
- **`claude.use_google_chrome`** = `false` and **`claude.user_data_dir_absolute`** empty (dedicated profile only).
- **`claude.user_data_dir_relative`** = `bot/browser_runner/profiles/claude-user-data`.
- Align **`bot/state/browser_bridge_session.json`** → `claude_conversation_label` with `00_ORCHESTRATOR` (bridge echoes this into `browser_bridge_next_action.json`).

## One-time human step (login)

From repo root (Windows: UTF-8 + `bot-cli` avoids Store `python` stub):

```powershell
cd C:\Users\openc\Desktop\testttt
chcp 65001
.\bot-cli.ps1 browser-open-target --target claude
```

In the opened window: sign in to Claude if needed, open the **orchestrator** chat (`00_ORCHESTRATOR`), confirm the address bar matches your configured **`start_url` / `expected_chat_url`**. Close the window when done — cookies live in the bot profile folder.

## Smoke commands (no inbox write)

```powershell
.\bot-cli.ps1 browser-bridge-prepare
.\bot-cli.ps1 browser-bridge-next-action
.\bot-cli.ps1 browser-run-step --no-write-inbox
.\bot-cli.ps1 browser-parse-last --from-next-action
```

(Equivalent: `PYTHONPATH=. python bot/cli.py …` if `python` is a real interpreter.)

## Success

- `browser-run-step --no-write-inbox` prints JSON with **`ok`: true** and status such as **`captured`** (handoff pasted, send clicked, assistant text captured to `bot/state/browser_runner_last_capture.txt`).
- `browser-parse-last --from-next-action` returns **`ok`: true** and valid extracted JSON for the expected kind (`plan` / `review` per next-action).

Copy outcome into **`bot/logs/CLAUDE_BROWSER_SMOKE_RESULT.md`** using the template there.

## Failure statuses (short)

| Status | Meaning |
|--------|--------|
| `profile_not_configured` | Missing/placeholder `start_url`, bad paths, or invalid combo (e.g. live Chrome profile + headless). |
| `chrome_profile_locked` / `chrome_launch_failed` | Profile lock or browser exited during launch (see message). |
| `wrong_target_conversation` | **URL guard**: page URL does not match `expected_chat_url`/`start_url`, and label fallback failed. |
| `missing_dom_target` | Composer or send control not found (see `dom_diagnostics` in JSON if present). |
| `missing_handoff` | `claude_handoff.md` missing for this cycle — prepare supervisor / outbox first. |
| `quota_or_limit_ui` | Rate limit / quota UI detected. |
| `import_error_playwright` | Install Playwright + `playwright install chromium` (see `PLAYWRIGHT_SETUP_WINDOWS.md`). |
| `human_verification_blocked` | Claude web anti-bot / human check — **not** a selector bug. Cycle should move to **`manual_gate`** when FSM was `waiting_claude`; continue with **manual** paste + inbox JSON. See **`bot/docs/CLAUDE_WEB_LIMITATION.md`**. |

More bridge recovery: `bot/browser_bridge/failure_modes.md`.

## After smoke is green

1. `.\bot-cli.ps1 browser-run-step` (with inbox write when appropriate).
2. `.\bot-cli.ps1 run-supervisor-once` (or `python bot/cli.py run-supervisor-once`).
3. Only then: bounded auto loop — `.\bot\scripts\run_auto_cycle.ps1 -MaxSteps 20` (see `bot/docs/AUTO_CYCLE_RUNNER.md`).

Do **not** enable Cursor Playwright until Claude browser smoke is stable.
