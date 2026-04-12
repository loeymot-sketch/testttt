"""
One-shot Claude Project automation via Playwright (persistent context).

Selectors and URLs come from browser_profiles.json — not hardcoded here beyond fallbacks.
"""

from __future__ import annotations

import re
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable
from urllib.parse import urlparse


CAPTURE_FILENAME = "browser_runner_last_capture.txt"


def _invalid_claude_start_url(url: str) -> str | None:
    """
    Return a short reason if URL is empty, not http(s), or still a template placeholder.
    """
    u = (url or "").strip()
    if not u:
        return "empty_url"
    low = u.lower()
    if not (low.startswith("http://") or low.startswith("https://")):
        return "not_http_url"
    if "your_project_id_here" in low:
        return "placeholder_url"
    if "paste_the_exact_claude_project_url_here" in low.replace("-", "_"):
        return "placeholder_url"
    if "placeholder" in low:
        return "placeholder_token"
    return None


_CHAT_UUID_RE = re.compile(r"/chat/([a-f0-9-]{36})", re.IGNORECASE)


def _chat_uuid_from_url(url: str) -> str | None:
    m = _CHAT_UUID_RE.search(url or "")
    return m.group(1).lower() if m else None


def _canonical_url_key(url: str) -> tuple[str, str, str]:
    p = urlparse((url or "").strip())
    net = (p.netloc or "").lower()
    if net.startswith("www."):
        net = net[4:]
    scheme = (p.scheme or "https").lower()
    path = (p.path or "").rstrip("/").lower()
    return (scheme, net, path)


def _expected_chat_url_matches_page(expected_cfg: str, page_url: str) -> bool:
    """
    True if page is the same Claude /chat/<uuid> as configured, or same canonical URL.
    Deterministic: compare UUID from path first, then (scheme, host, path).
    """
    exp = (expected_cfg or "").strip()
    act = (page_url or "").strip()
    if not exp or not act:
        return False
    ue, ua = _chat_uuid_from_url(exp), _chat_uuid_from_url(act)
    if ue and ua:
        return ue == ua
    return _canonical_url_key(exp) == _canonical_url_key(act)


@dataclass
class ClaudeRunResult:
    ok: bool
    status: str
    message: str
    capture_path: Path | None = None
    target_verification: str | None = None


def _sync_playwright() -> Callable[..., Any] | None:
    try:
        from playwright.sync_api import sync_playwright

        return sync_playwright
    except ImportError:
        return None


def _quota_hit(page: Any, snippets: list[str]) -> str | None:
    try:
        body = page.content().lower()
    except Exception:
        return None
    for s in snippets:
        if s.lower() in body:
            return s
    return None


def _first_visible_locator(page: Any, selector_csv: str):
    from playwright.sync_api import TimeoutError as PWTimeout

    for sel in [s.strip() for s in selector_csv.split(",") if s.strip()]:
        loc = page.locator(sel).first
        try:
            loc.wait_for(state="visible", timeout=5000)
            return loc
        except PWTimeout:
            continue
    return None


def open_claude_target(
    repo_root: Path,
    profile: dict[str, Any],
    *,
    headless: bool,
) -> ClaudeRunResult:
    """Navigate once; persist cookies in user_data_dir."""
    sp_fn = _sync_playwright()
    if sp_fn is None:
        return ClaudeRunResult(False, "import_error_playwright", "Install: pip install playwright && playwright install chromium")

    claude = profile.get("claude") or {}
    url = str(claude.get("start_url", "")).strip()
    bad = _invalid_claude_start_url(url)
    if bad:
        return ClaudeRunResult(
            False,
            "profile_not_configured",
            "Set a real https://claude.ai/projects/... URL in claude.start_url (see browser_profiles.example.json).",
        )

    rel = str(claude.get("user_data_dir_relative", "bot/browser_runner/profiles/claude-user-data"))
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
            try:
                page.goto(
                    url,
                    wait_until="domcontentloaded",
                    timeout=int(claude.get("timeouts_ms", {}).get("navigation", 60000)),
                )
            except Exception as e:
                return ClaudeRunResult(False, "navigation_failed", str(e)[:500])
            time.sleep(2)
            q = _quota_hit(page, list(claude.get("quota_snippets", [])))
            if q:
                return ClaudeRunResult(False, "quota_or_limit_ui", f"matched:{q}")
        finally:
            ctx.close()
    return ClaudeRunResult(True, "opened", str(udir))


def run_claude_browser_step(
    repo_root: Path,
    profile: dict[str, Any],
    next_action: dict[str, Any],
    state_dir: Path,
    *,
    headless: bool,
) -> ClaudeRunResult:
    """Paste handoff, send, capture assistant text to state_dir/CAPTURE_FILENAME."""
    sp_fn = _sync_playwright()
    if sp_fn is None:
        return ClaudeRunResult(False, "import_error_playwright", "Install: pip install playwright && playwright install chromium")

    inst = next_action.get("instructions") or {}
    paste_src = Path(str(inst.get("paste_source_file", "")))
    label = str(inst.get("conversation_label", "00_ORCHESTRATOR"))
    if not inst.get("handoff_must_exist", False) or not paste_src.is_file():
        return ClaudeRunResult(False, "missing_handoff", f"paste_source_file missing or not on disk: {paste_src}")

    handoff = paste_src.read_text(encoding="utf-8")
    claude = profile.get("claude") or {}
    url = str(claude.get("start_url", "")).strip()
    if _invalid_claude_start_url(url):
        return ClaudeRunResult(
            False,
            "profile_not_configured",
            "Configure a real https://claude.ai/projects/... URL in claude.start_url.",
        )

    rel = str(claude.get("user_data_dir_relative", "bot/browser_runner/profiles/claude-user-data"))
    udir = (repo_root / rel).resolve()
    udir.mkdir(parents=True, exist_ok=True)
    sel = claude.get("selectors") or {}
    composer_sel = str(sel.get("composer_textarea", "textarea"))
    send_sel = str(sel.get("send_button", "button[aria-label*='Send']"))
    assistant_sel_csv = str(sel.get("assistant_turn", "div[data-is-streaming], .font-claude-message"))
    timeouts = claude.get("timeouts_ms") or {}
    after_send_ms = int(timeouts.get("after_send", 120000))
    snippets = list(claude.get("quota_snippets", []))

    capture_path = state_dir / CAPTURE_FILENAME

    with sp_fn() as p:
        ctx = p.chromium.launch_persistent_context(
            str(udir),
            headless=headless,
            viewport={"width": 1280, "height": 800},
        )
        try:
            page = ctx.pages[0] if ctx.pages else ctx.new_page()
            try:
                page.goto(
                    url,
                    wait_until="domcontentloaded",
                    timeout=int(timeouts.get("navigation", 60000)),
                )
            except Exception as e:
                return ClaudeRunResult(False, "navigation_failed", str(e)[:500])

            q = _quota_hit(page, snippets)
            if q:
                return ClaudeRunResult(False, "quota_or_limit_ui", f"matched:{q}")

            expected_for_url = str(
                claude.get("expected_chat_url") or claude.get("start_url") or ""
            ).strip()
            page_url = str(page.url or "").strip()
            url_ok = (
                _expected_chat_url_matches_page(expected_for_url, page_url)
                if expected_for_url
                else False
            )
            try:
                body_lower = page.content().lower()
            except Exception:
                body_lower = ""
            label_ok = bool(label.strip()) and label.lower() in body_lower

            if not url_ok and not label_ok:
                return ClaudeRunResult(
                    False,
                    "wrong_target_conversation",
                    (
                        "Chat URL did not match configured expected_chat_url/start_url "
                        f"(page={page_url!r} vs expected={expected_for_url!r}) "
                        f"and page text does not contain label:{label!r}"
                    ),
                )

            target_verification = "url_match" if url_ok else "label_match"

            composer = _first_visible_locator(page, composer_sel)
            if composer is None:
                return ClaudeRunResult(False, "missing_dom_target", f"No visible composer for selectors:{composer_sel!r}")

            composer.click()
            composer.fill("")
            composer.fill(handoff)

            send_btn = _first_visible_locator(page, send_sel)
            if send_btn is None:
                return ClaudeRunResult(False, "missing_dom_target", f"No send control for selectors:{send_sel!r}")
            send_btn.click()

            time.sleep(min(after_send_ms / 1000.0, 120.0))

            q2 = _quota_hit(page, snippets)
            if q2:
                return ClaudeRunResult(False, "quota_or_limit_ui", f"matched:{q2}")

            text = ""
            for part in [s.strip() for s in assistant_sel_csv.split(",") if s.strip()]:
                loc = page.locator(part).all()
                if loc:
                    text = loc[-1].inner_text()
                    break
            if not text:
                return ClaudeRunResult(False, "missing_assistant_response", "No assistant nodes matched selectors")
            if not (text or "").strip():
                return ClaudeRunResult(False, "missing_assistant_response", "Assistant node empty")

            capture_path.write_text(text, encoding="utf-8")
            return ClaudeRunResult(
                True,
                "captured",
                "ok",
                capture_path=capture_path,
                target_verification=target_verification,
            )
        finally:
            ctx.close()
