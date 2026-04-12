# Browser runner (one step per invocation)

Deterministic bridge between `bot/state/browser_bridge_next_action.json` and a **single** human-supervised browser or clipboard action. There is **no** daemon, **no** autonomous multi-step chain, and **no** Claude or Cursor HTTP API.

## Prerequisites

1. Python env with repo root on `PYTHONPATH` (same as other bot commands):

   `PYTHONPATH=. python bot/cli.py browser-bridge-prepare`

2. **Playwright** (only if you use Playwright paths — Claude steps, or Cursor with `automation_mode: playwright`). On Windows, step-by-step (venv, `FOODKING_PYTHON`, troubleshooting **`import_error_playwright`**) : **`bot/docs/PLAYWRIGHT_SETUP_WINDOWS.md`**.

   ```bash
   pip install playwright
   playwright install chromium
   ```

3. Copy `browser_profiles.example.json` → `browser_profiles.json` and set at least `claude.start_url` (direct `/chat/<uuid>` URL recommended). Optional `claude.expected_chat_url` overrides which URL is compared to `page.url` for the **URL-first** conversation guard (defaults to `start_url`).

4. Persistent profile directories under `bot/browser_runner/profiles/` are created automatically (`user_data_dir_relative` in the profile). Log in once in headed mode, then reuse the profile.

## Commands (via `bot/cli.py`)

| Command | Purpose |
|--------|---------|
| `browser-bridge-prepare` | Refresh `browser_bridge_next_action.json` (run before runner). |
| `browser-run-step` | Read next action, execute **one** step (Claude paste/capture, Cursor clipboard or Playwright, or return pending statuses). |
| `browser-open-target` | `--target claude\|cursor` — open project URL once to warm session. |
| `browser-parse-last` | Parse `bot/state/browser_runner_last_capture.txt` to JSON (use `--from-next-action` or `--kind`). |
| `browser-write-inbox` | Validate a JSON file and atomically write to the inbox path from the current next-action. |

## Multi-step automation (Windows)

For a **bounded loop** (prepare → next-action → optional `browser-run-step` → `run-supervisor-once`), use `bot/scripts/run_auto_cycle.ps1` and read `bot/docs/AUTO_CYCLE_RUNNER.md`.

Examples:

```powershell
$env:PYTHONPATH="."
python bot/cli.py browser-bridge-prepare
python bot/cli.py browser-run-step
python bot/cli.py browser-run-step --no-write-inbox
python bot/cli.py browser-open-target --target claude
python bot/cli.py browser-parse-last --from-next-action
python bot/cli.py browser-write-inbox --file .\plan.json
```

## Windows notes

- Default Cursor path is **clipboard**: handoff is copied with PowerShell `Set-Clipboard`; you paste into the Agent manually. No JSON is fabricated.
- Paths in `browser_profiles.json` should use forward slashes or escaped backslashes; prefer repo-relative `user_data_dir_relative`.

## Safety and limits

See `guardrails.md`. In short: malformed assistant text → **no** inbox write; wrong tab/conversation label → stop; quota/limit strings in page → stop; missing capture → parse fails.

## Related

- `bot/browser_bridge/` — builds `browser_bridge_next_action.json` from cycle FSM + supervisor paths.
- `bot/supervisor.py` — ingests inbox JSON after files are on disk.
