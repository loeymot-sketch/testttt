# Legacy web orchestration — archive note

## What was moved

From the repository root into `_archive/ignored_legacy_web_orchestration/`:

| Item | Role (before archive) |
|------|------------------------|
| `bot/` | File-based bot v0: supervisor, cycle FSM, browser bridge / Playwright runner, inbox/outbox, Python CLI, onboarding copies under `bot/onboarding/`, etc. |
| `bot-cli.ps1` | Windows wrapper invoking `bot/cli.py` with `PYTHONPATH`. |
| `bot-cli.cmd` | CMD wrapper for the same. |
| `_UPLOAD_CLAUDE_TEMP/` | Staging / upload pack for external Claude project web orchestration. |

Internal layout is preserved: `bot/` remains a single subtree under this folder (`…/ignored_legacy_web_orchestration/bot/…`).

## Why it was moved

To **remove the old external bot / web-Claude orchestration surface** from the active project tree so day-to-day work uses a **Cursor-local, semi-autonomous workflow** (plans and execution in-repo without depending on the archived CLI, supervisor dropzones, or browser bridge at the repo root).

Nothing was deleted; paths changed only under `_archive/`.

## What this archive is **not**

- It is **not** the canonical home for FoodKing application code (`app/`, `routes/`, etc.).
- It is **not** a replacement for `docs/`, `reports/`, `workflows/`, `AGENTS.md`, or `.cursor/` — those stay at the repo root and remain the active governance layer for Cursor-local work.

## Active direction

**New active direction:** Cursor-local semi-autonomous workflow — humans and Cursor use `docs/`, `reports/`, `workflows/`, and project rules without requiring the archived `bot-cli` / supervisor cycle machine at the repository root.

Archived material may still be consulted or revived manually from `_archive/ignored_legacy_web_orchestration/` if needed for history or one-off operations.
