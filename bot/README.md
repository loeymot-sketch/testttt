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

## Getting started

1. Copy example configs and fill placeholders (see comments inside each JSON).
2. Run `scripts/bootstrap.sh` (Unix) or `scripts/bootstrap.ps1` (Windows) to verify paths and create empty state/log files if missing.
3. Read `docs/BOT_ARCHITECTURE.md` and `docs/BOT_RUNTIME_STATES.md` before wiring the first cycle.

**Playwright** and **Telegram** are integrated at the architecture level; concrete drivers are TBD in implementation phase.
