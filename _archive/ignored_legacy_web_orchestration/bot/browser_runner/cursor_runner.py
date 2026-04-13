"""
One-shot Cursor coordination: clipboard (default) or Playwright when start_url set.
"""

from __future__ import annotations

import subprocess
import tempfile
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable

from bot.browser_runner import claude_runner as cr


@dataclass
class CursorRunResult:
    ok: bool
    status: str
    message: str


def _sync_playwright() -> Callable[..., Any] | None:
    try:
        from playwright.sync_api import sync_playwright

        return sync_playwright
    except ImportError:
        return None


def _copy_clipboard_windows(text: str) -> None:
    """Write UTF-8 temp file and pipe to Set-Clipboard (Windows)."""
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".txt", delete=False) as f:
        f.write(text)
        tmp = Path(f.name)
    try:
        ps = (
            f"$p = Get-Item -LiteralPath '{str(tmp).replace(chr(39), chr(39)+chr(39))}'; "
            "Get-Content -LiteralPath $p.FullName -Raw -Encoding UTF8 | Set-Clipboard"
        )
        subprocess.run(
            ["powershell", "-NoProfile", "-NonInteractive", "-Command", ps],
            check=True,
            capture_output=True,
            text=True,
        )
    finally:
        tmp.unlink(missing_ok=True)


def open_cursor_target(
    repo_root: Path,
    profile: dict[str, Any],
    *,
    headless: bool,
) -> CursorRunResult:
    cur = profile.get("cursor") or {}
    mode = str(cur.get("automation_mode", "clipboard")).lower()
    url = str(cur.get("start_url", "")).strip()
    if mode == "playwright" and url:
        sp_fn = _sync_playwright()
        if sp_fn is None:
            return CursorRunResult(False, "import_error_playwright", "pip install playwright && playwright install chromium")
        rel = str(cur.get("user_data_dir_relative", "bot/browser_runner/profiles/cursor-user-data"))
        udir = (repo_root / rel).resolve()
        udir.mkdir(parents=True, exist_ok=True)
        with sp_fn() as p:
            ctx = p.chromium.launch_persistent_context(
                str(udir),
                headless=headless,
                viewport={"width": 1280, "height": 800},
            )
            try:
                page = ctx.pages[0] if ctx.pages else ctx.new_page()
                page.goto(url, wait_until="domcontentloaded", timeout=60000)
                time.sleep(2)
            finally:
                ctx.close()
        return CursorRunResult(True, "opened", str(udir))
    return CursorRunResult(
        True,
        "clipboard_mode_no_browser",
        "Cursor automation_mode is clipboard — open Cursor manually; profile session not used.",
    )


def run_cursor_agent_step(
    repo_root: Path,
    profile: dict[str, Any],
    next_action: dict[str, Any],
    *,
    headless: bool,
) -> CursorRunResult:
    inst = next_action.get("instructions") or {}
    paste_src = Path(str(inst.get("paste_source_file", "")))
    if not inst.get("handoff_must_exist", False) or not paste_src.is_file():
        return CursorRunResult(False, "missing_handoff", str(paste_src))

    body = paste_src.read_text(encoding="utf-8")
    cur = profile.get("cursor") or {}
    mode = str(cur.get("automation_mode", "clipboard")).lower()
    url = str(cur.get("start_url", "")).strip()

    if mode == "playwright" and url:
        # Reuse Claude-style paste flow with Cursor-specific profile selectors if present
        fake_na = {
            "instructions": {
                **inst,
                "conversation_label": str(cur.get("session_label_hint", "cursor")),
            }
        }
        sel = cur.get("selectors") or {}
        claude_shape = {
            "claude": {
                "start_url": url,
                "user_data_dir_relative": cur.get(
                    "user_data_dir_relative", "bot/browser_runner/profiles/cursor-user-data"
                ),
                "timeouts_ms": cur.get("timeouts_ms", {"navigation": 60000, "after_send": 90000}),
                "quota_snippets": [],
                "selectors": sel
                or {
                    "composer_textarea": "textarea",
                    "send_button": "button[type='submit']",
                    "assistant_turn": "pre, code",
                },
            }
        }
        state_dir = repo_root / "bot" / "state"
        r = cr.run_claude_browser_step(repo_root, claude_shape, fake_na, state_dir, headless=headless)
        return CursorRunResult(r.ok, r.status, r.message)

    try:
        _copy_clipboard_windows(body)
    except (OSError, subprocess.CalledProcessError) as e:
        return CursorRunResult(False, "clipboard_failed", str(e))

    return CursorRunResult(
        False,
        "human_must_complete_cursor",
        "Handoff copied to Windows clipboard. Paste into Cursor Agent, complete work, "
        "then create cursor_done.json / validation.json manually or reconfigure cursor.automation_mode=playwright.",
    )
