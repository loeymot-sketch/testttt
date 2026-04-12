# FoodKing orchestration bot — runtime layout

This tree holds the **autonomous orchestration bot** that will coordinate Claude conversations, Cursor execution cycles, report sync, model routing, Playwright triggers, and Telegram alerts. **Application code** (`app/`, `routes/`, etc.) is out of scope here.

## Folders

| Path | Purpose |
|------|--------|
| **`config/`** | Machine-local and environment-specific settings. Copy `*.example.json` → committed or secret filenames (see each file’s header) and adjust paths, API keys, and routing. |
| **`prompts/`** | Versioned prompt **templates** for Claude and Cursor wrappers. The bot will render these with task context; no secrets inside templates. |
| **`state/`** | Ephemeral runtime state (current cycle id, locks, last heartbeat). **Not** for long-term docs — use `reports/` in repo root for human-facing artifacts. |
| **`logs/`** | Bot process logs (append-only). Rotate or archive per your ops policy. |
| **`scripts/`** | Bootstrap and maintenance entrypoints (shell/PowerShell). Full daemon/worker logic will live elsewhere or in a future package. |
| **`docs/`** | Architecture and state machine reference for operators and future implementers. |
| **`runtime/`** | Python v0 orchestration core (state, intake, handoffs, cycle controller). Stdlib only. |
| **`templates/`** | JSON Schemas for persisted packets (validation with external tools). |
| **`examples/`** | Example JSON payloads aligned with **`templates/`** and **`runtime/models.py`**, plus walkthroughs. |
| **`cli.py`** | Local operator entrypoint (`python bot/cli.py …`) — file-backed transitions only. |

## Getting started

1. Copy example configs and fill placeholders (see comments inside each JSON).
2. Run `scripts/bootstrap.sh` (Unix) or `scripts/bootstrap.ps1` (Windows) to verify paths and create empty state/log files if missing.
3. Read `docs/BOT_ARCHITECTURE.md` and `docs/BOT_RUNTIME_STATES.md` before wiring the first cycle.

**Playwright** and **Telegram** are integrated at the architecture level; concrete drivers are TBD in implementation phase.

## Runtime v0

**What exists now**

- **`bot/runtime/`** — Python **stdlib-only** orchestration core (typed dataclasses, no network calls):
  - **`state_manager.py`** — atomic read/write of `bot/state/cycle_state.json`.
  - **`intake_builder.py`** — builds **`ClaudeIntakePacket`** from repo paths in `bot/config/paths.json` (with defaults matching `paths.example.json`).
  - **`handoff_manager.py`** — saves/loads JSON under `bot/state/handoffs/<cycle_id>/` (`claude_intake.json`, `claude_response.json`, `cursor_execution.json`).
  - **`cycle_controller.py`** — explicit transitions (`idle` / `completed` → `preparing_intake` → `waiting_claude` → … → `completed`), plus **`blocked`** / **`manual_gate`**; **`claude_round`** distinguishes plan vs review while in `waiting_claude`.
  - **`models.py`** — `CycleState`, intake/response/cursor packets, `ValidationStatus`, `PlaywrightStatus`, `ModelRoute`.
  - **`init.py`** — `RuntimePaths`, JSON helpers, **`resolve_model_route()`** from `bot/config/model_routing.json`.
- **`bot/templates/*.schema.json`** — strict JSON Schemas aligned with the Python shapes.
- **`bot/examples/*.example.json`** — sample payloads for operators and tests.

**How to import** (from repository root, with `PYTHONPATH` including the repo root):

```text
PYTHONPATH=. python -c "from bot.runtime import CycleController, RuntimePaths; print('ok')"
```

**Intentionally not automated yet**

- No Claude API, no Telegram send, no Playwright runner, no Git operations, no long-running daemon loop.
- **`placeholder_*`** methods on **`CycleController`** raise **`NotImplementedError`** with explicit messages until those integrations are added.

## How to operate locally now

1. **Configs** — Use the committed real files under **`bot/config/`** (`bot_config.json`, `paths.json`, `model_routing.json`, `telegram.json`). Adjust `repo_root` only if the bot config is not under `<repo>/bot/config/`. Keep Telegram disabled until wired.
2. **CLI** — From repo root (Windows PowerShell example):

   ```powershell
   $env:PYTHONPATH = "."
   python bot/cli.py show-state
   python bot/cli.py begin-cycle --task-id T-1 --goal "Describe the task"
   ```

3. **Handoffs** — After `begin-cycle`, edit or copy **`bot/state/handoffs/<cycle_id>/claude_intake.json`** into your Claude project; paste the response into a JSON file and run `register-claude-response` / `register-claude-review` as documented.
4. **Docs** — Operator procedure and one full manual walkthrough: **`bot/docs/BOT_LOCAL_USAGE.md`**, **`bot/examples/manual_cycle_walkthrough.md`**.

