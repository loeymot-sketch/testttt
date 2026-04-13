# Browser runner (one step per invocation)

Deterministic bridge between `bot/state/browser_bridge_next_action.json` and a **single** human-supervised browser or clipboard action. There is **no** daemon, **no** autonomous multi-step chain, and **no** Claude or Cursor HTTP API.

## Prerequisites

1. **Windows:** from repo root use **`.\bot-cli.ps1 <command>`** so a real `python.exe` is used (the plain `python` command often hits the **Microsoft Store stub** and prints *Python est introuvable*). `bot-cli.ps1` sets `PYTHONPATH` for you.

   Example: `.\bot-cli.ps1 browser-bridge-prepare`

   **Linux/macOS** (or if `python` is already a real install): `PYTHONPATH=. python bot/cli.py browser-bridge-prepare`

2. **Playwright** (only if you use Playwright paths — Claude steps, or Cursor with `automation_mode: playwright`). On Windows, step-by-step (venv, `FOODKING_PYTHON`, troubleshooting **`import_error_playwright`**) : **`bot/docs/PLAYWRIGHT_SETUP_WINDOWS.md`**.

   ```bash
   pip install playwright
   playwright install chromium
   ```

3. Copy `browser_profiles.example.json` → `browser_profiles.json` and set at least `claude.start_url` (direct `/chat/<uuid>` URL recommended). Optional `claude.expected_chat_url` overrides which URL is compared to `page.url` for the **URL-first** conversation guard (defaults to `start_url`). Composer: use a comma-separated `claude.selectors.composer_textarea` list; built-in fallbacks (textarea, `contenteditable`, ProseMirror, etc.) are merged after your list; polling uses `timeouts_ms.composer_wait` (default 30000).

4. **Recommended (automation PCs):** isolated Chromium profile — `user_data_dir_relative` → `bot/browser_runner/profiles/claude-user-data` (auto-created). One-time login there, then smoke: **`bot/docs/CLAUDE_BROWSER_SMOKE_CHECKLIST.md`**. Optional advanced path: real Google Chrome user data (`use_google_chrome`, `user_data_dir_absolute`, close all Chrome first, headed only); see `PLAYWRIGHT_SETUP_WINDOWS.md`.

## Commands (via `bot-cli.ps1` on Windows, else `python bot/cli.py`)

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
.\bot-cli.ps1 browser-bridge-prepare
.\bot-cli.ps1 browser-run-step
.\bot-cli.ps1 browser-run-step --no-write-inbox
.\bot-cli.ps1 browser-open-target --target claude
.\bot-cli.ps1 browser-parse-last --from-next-action
.\bot-cli.ps1 browser-write-inbox --file .\plan.json
```

## Windows notes

- If **`python bot/cli.py`** fails with *Python est introuvable* / Store prompt: use **`.\bot-cli.ps1`** only, or disable **App execution aliases** for `python.exe` / `python3.exe` (Settings → Apps → Advanced app settings → App execution aliases).
- Default Cursor path is **clipboard**: handoff is copied with PowerShell `Set-Clipboard`; you paste into the Agent manually. No JSON is fabricated.
- Paths in `browser_profiles.json` should use forward slashes or escaped backslashes; prefer repo-relative `user_data_dir_relative`.

## Safety and limits

See `guardrails.md`. In short: malformed assistant text → **no** inbox write; wrong tab/conversation label → stop; quota/limit strings in page → stop; missing capture → parse fails.

## Related

- `bot/browser_bridge/` — builds `browser_bridge_next_action.json` from cycle FSM + supervisor paths.
- `bot/supervisor.py` — ingests inbox JSON after files are on disk.
