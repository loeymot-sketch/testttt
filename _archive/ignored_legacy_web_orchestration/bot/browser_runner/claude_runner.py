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


def _resolve_claude_user_data_dir(repo_root: Path, claude: dict[str, Any]) -> Path:
    abs_p = str(claude.get("user_data_dir_absolute", "")).strip()
    if abs_p:
        return Path(abs_p).expanduser().resolve()
    rel = str(claude.get("user_data_dir_relative", "bot/browser_runner/profiles/claude-user-data"))
    return (repo_root / rel).resolve()


def _prepare_claude_user_data_dir(udir: Path, claude: dict[str, Any]) -> None:
    """Only create the repo-local Chromium profile; never mkdir() on a real Chrome User Data path."""
    if str(claude.get("user_data_dir_absolute", "")).strip():
        return
    udir.mkdir(parents=True, exist_ok=True)


def _uses_live_google_chrome_user_data(claude: dict[str, Any]) -> bool:
    return bool(claude.get("use_google_chrome", False)) and bool(
        str(claude.get("user_data_dir_absolute", "")).strip()
    )


def _singleton_lock_message(udir: Path) -> str | None:
    """Chrome refuses a second process on the same User Data while lock files exist."""
    for name in ("SingletonLock", "SingletonCookie", "SingletonSocket"):
        p = udir / name
        try:
            if p.exists():
                return (
                    f"Chrome profile lock present ({name}). Another Chrome is using this User Data, "
                    "or a stale lock remains after a crash. Quit every Chrome window (tray included), "
                    "wait a few seconds, retry. If Chrome is fully closed and it persists, delete only "
                    f"that lock file under {udir} at your own risk."
                )
        except OSError:
            continue
    return None


def _persistent_context_launch_kwargs(claude: dict[str, Any]) -> dict[str, Any]:
    """Google Chrome + system profile: set use_google_chrome + user_data_dir_absolute (+ optional chrome_profile_directory)."""
    extra: dict[str, Any] = {}
    if bool(claude.get("use_google_chrome", False)):
        extra["channel"] = "chrome"
        # Playwright defaults include --disable-extensions; real Chrome User Data often exits immediately (exit 21) if forced that way.
        extra["ignore_default_args"] = [
            "--disable-extensions",
            "--disable-component-extensions-with-background-pages",
        ]
    prof = str(claude.get("chrome_profile_directory", "")).strip()
    if prof:
        extra["args"] = [f"--profile-directory={prof}"]
    return extra


def _launch_persistent_or_error(
    p: Any,
    udir: Path,
    *,
    headless: bool,
    launch_kw: dict[str, Any],
) -> tuple[Any | None, str | None]:
    """Returns (context, None) or (None, error_message)."""
    try:
        ctx = p.chromium.launch_persistent_context(
            str(udir),
            headless=headless,
            viewport={"width": 1280, "height": 800},
            **launch_kw,
        )
        return ctx, None
    except Exception as e:
        msg = str(e)[:900]
        hint = (
            " Quit all Chrome instances; confirm user_data_dir_absolute; "
            "do not use --headless with use_google_chrome + live User Data; "
            "or use a copied profile under bot/browser_runner/profiles/ (see docs)."
        )
        return None, msg + hint

# Appended after profile `composer_textarea` (comma-separated); order preserved, deduped.
_BUILTIN_COMPOSER_SELECTORS: tuple[str, ...] = (
    "textarea",
    "div[contenteditable='true']",
    "div[contenteditable=\"true\"]",
    "[contenteditable='true']",
    "[role='textbox'][contenteditable='true']",
    "[role='textbox'][contenteditable]",
    "div.ProseMirror[contenteditable='true']",
    "[data-testid='composer-text-input']",
    "[data-testid*='composer']",
)


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
    dom_diagnostics: dict[str, Any] | None = None


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


# Claude / CDN anti-bot copy (FR/EN); extend via claude.human_verification_snippets in browser_profiles.json.
_DEFAULT_HUMAN_VERIFICATION_SNIPPETS: tuple[str, ...] = (
    "vérifions que vous êtes humain",
    "verifying you are human",
    "verify you're human",
    "verify you are human",
    "checking if you are human",
    "confirm you're not a robot",
    "prove you are human",
    "unusual traffic from your computer",
    "before we continue, we need to verify",
    "please complete the security check",
)


def _human_verification_snippets(claude: dict[str, Any]) -> list[str]:
    extra = claude.get("human_verification_snippets")
    out: list[str] = []
    seen: set[str] = set()
    for s in list(_DEFAULT_HUMAN_VERIFICATION_SNIPPETS):
        k = s.lower()
        if k not in seen:
            seen.add(k)
            out.append(s)
    if isinstance(extra, list):
        for s in extra:
            t = str(s).strip()
            if not t:
                continue
            k = t.lower()
            if k not in seen:
                seen.add(k)
                out.append(t)
    return out


def _human_verification_hit(page: Any, snippets: list[str]) -> str | None:
    return _quota_hit(page, snippets)


def _merged_composer_selector_list(user_csv: str) -> list[str]:
    seen: set[str] = set()
    out: list[str] = []
    for s in [x.strip() for x in (user_csv or "").split(",") if x.strip()]:
        k = s.lower()
        if k not in seen:
            seen.add(k)
            out.append(s)
    for s in _BUILTIN_COMPOSER_SELECTORS:
        k = s.lower()
        if k not in seen:
            seen.add(k)
            out.append(s)
    return out


def _scan_composer_selector(page: Any, sel: str) -> dict[str, Any]:
    row: dict[str, Any] = {"selector": sel, "total": 0, "visible_count": 0, "attached_hidden": 0}
    loc = page.locator(sel)
    try:
        n = loc.count()
    except Exception as e:
        row["error"] = str(e)[:200]
        return row
    row["total"] = n
    for i in range(min(n, 40)):
        nth = loc.nth(i)
        try:
            if nth.is_visible():
                row["visible_count"] += 1
            elif nth.is_attached():
                row["attached_hidden"] += 1
        except Exception:
            pass
    return row


def _first_visible_nth_for_selector(page: Any, sel: str) -> Any | None:
    loc = page.locator(sel)
    try:
        n = loc.count()
    except Exception:
        return None
    for i in range(min(n, 40)):
        nth = loc.nth(i)
        try:
            if nth.is_visible():
                return nth
        except Exception:
            continue
    return None


def _wait_for_composer_locator(
    page: Any,
    selectors: list[str],
    composer_wait_ms: int,
) -> tuple[Any | None, dict[str, Any]]:
    """
    Poll until first visible composer or composer_wait_ms elapses.
    Returns (locator, diagnostics dict).
    """
    page_url = str(page.url or "")
    deadline = time.monotonic() + max(0, int(composer_wait_ms)) / 1000.0
    last_per: list[dict[str, Any]] = []
    while time.monotonic() < deadline:
        last_per = [_scan_composer_selector(page, s) for s in selectors]
        for sel in selectors:
            pick = _first_visible_nth_for_selector(page, sel)
            if pick is not None:
                return pick, {
                    "kind": "composer",
                    "selectors_tried": selectors,
                    "page_url": page_url,
                    "matched_selector": sel,
                    "per_selector": last_per,
                }
        time.sleep(0.4)
    return None, {
        "kind": "composer",
        "selectors_tried": selectors,
        "page_url": page_url,
        "per_selector": last_per,
        "note": "No visible composer before composer_wait elapsed; see visible_count vs attached_hidden.",
    }


def _fill_composer(composer: Any, text: str, page: Any) -> None:
    """Clear and paste handoff; fill() first, then insert_text for stubborn editors."""
    composer.click()
    try:
        composer.fill("")
        composer.fill(text)
    except Exception:
        try:
            page.keyboard.insert_text(text)
        except Exception:
            ps = getattr(composer, "press_sequentially", None)
            if callable(ps):
                composer.press_sequentially(text, delay=1)
            else:
                page.keyboard.type(text, delay=1)


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
            "Set a real https://claude.ai/chat/... or project URL in claude.start_url (see browser_profiles.example.json).",
        )

    udir = _resolve_claude_user_data_dir(repo_root, claude)
    if str(claude.get("user_data_dir_absolute", "")).strip() and not udir.is_dir():
        return ClaudeRunResult(
            False,
            "profile_not_configured",
            f"user_data_dir_absolute does not exist or is not a directory: {udir}",
        )
    _prepare_claude_user_data_dir(udir, claude)
    launch_kw = _persistent_context_launch_kwargs(claude)

    if _uses_live_google_chrome_user_data(claude) and headless:
        return ClaudeRunResult(
            False,
            "profile_not_configured",
            "use_google_chrome with user_data_dir_absolute requires headed mode (omit --headless).",
        )
    if _uses_live_google_chrome_user_data(claude):
        lock_msg = _singleton_lock_message(udir)
        if lock_msg:
            return ClaudeRunResult(False, "chrome_profile_locked", lock_msg)

    with sp_fn() as p:
        ctx, err = _launch_persistent_or_error(p, udir, headless=headless, launch_kw=launch_kw)
        if err or ctx is None:
            return ClaudeRunResult(False, "chrome_launch_failed", err or "launch_failed")
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
            hv_snips = _human_verification_snippets(claude)
            hv = _human_verification_hit(page, hv_snips)
            if hv:
                return ClaudeRunResult(
                    False,
                    "human_verification_blocked",
                    f"matched:{hv!r} (Claude web anti-bot / human check — not a bot defect)",
                )
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
    claude_pre = profile.get("claude") or {}
    _raw_lbl = inst.get("conversation_label")
    label = (
        str(_raw_lbl).strip()
        if _raw_lbl is not None and str(_raw_lbl).strip()
        else str(claude_pre.get("orchestrator_conversation_label", "00_ORCHESTRATOR")).strip() or "00_ORCHESTRATOR"
    )
    if not inst.get("handoff_must_exist", False) or not paste_src.is_file():
        return ClaudeRunResult(False, "missing_handoff", f"paste_source_file missing or not on disk: {paste_src}")

    handoff = paste_src.read_text(encoding="utf-8")
    claude = claude_pre
    url = str(claude.get("start_url", "")).strip()
    if _invalid_claude_start_url(url):
        return ClaudeRunResult(
            False,
            "profile_not_configured",
            "Configure a real https://claude.ai/chat/... or project URL in claude.start_url.",
        )

    udir = _resolve_claude_user_data_dir(repo_root, claude)
    if str(claude.get("user_data_dir_absolute", "")).strip() and not udir.is_dir():
        return ClaudeRunResult(
            False,
            "profile_not_configured",
            f"user_data_dir_absolute does not exist or is not a directory: {udir}",
        )
    _prepare_claude_user_data_dir(udir, claude)
    launch_kw = _persistent_context_launch_kwargs(claude)

    if _uses_live_google_chrome_user_data(claude) and headless:
        return ClaudeRunResult(
            False,
            "profile_not_configured",
            "use_google_chrome with user_data_dir_absolute requires headed mode (omit --headless).",
        )
    if _uses_live_google_chrome_user_data(claude):
        lock_msg = _singleton_lock_message(udir)
        if lock_msg:
            return ClaudeRunResult(False, "chrome_profile_locked", lock_msg)

    sel = claude.get("selectors") or {}
    composer_sel = str(sel.get("composer_textarea", "textarea"))
    composer_selectors = _merged_composer_selector_list(composer_sel)
    send_sel = str(sel.get("send_button", "button[aria-label*='Send']"))
    assistant_sel_csv = str(sel.get("assistant_turn", "div[data-is-streaming], .font-claude-message"))
    timeouts = claude.get("timeouts_ms") or {}
    after_send_ms = int(timeouts.get("after_send", 120000))
    composer_wait_ms = int(timeouts.get("composer_wait", 30000))
    snippets = list(claude.get("quota_snippets", []))

    capture_path = state_dir / CAPTURE_FILENAME

    with sp_fn() as p:
        ctx, err = _launch_persistent_or_error(p, udir, headless=headless, launch_kw=launch_kw)
        if err or ctx is None:
            return ClaudeRunResult(False, "chrome_launch_failed", err or "launch_failed")
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

            time.sleep(2)

            hv_snips = _human_verification_snippets(claude)
            hv = _human_verification_hit(page, hv_snips)
            if hv:
                return ClaudeRunResult(
                    False,
                    "human_verification_blocked",
                    f"matched:{hv!r} (Claude web anti-bot / human check — not a bot defect)",
                )

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

            composer, comp_diag = _wait_for_composer_locator(
                page, composer_selectors, composer_wait_ms
            )
            if composer is None:
                return ClaudeRunResult(
                    False,
                    "missing_dom_target",
                    "No visible composer found after composer_wait (see dom_diagnostics).",
                    dom_diagnostics=comp_diag,
                )

            _fill_composer(composer, handoff, page)

            send_btn = _first_visible_locator(page, send_sel)
            if send_btn is None:
                return ClaudeRunResult(False, "missing_dom_target", f"No send control for selectors:{send_sel!r}")
            send_btn.click()

            time.sleep(min(after_send_ms / 1000.0, 120.0))

            hv2 = _human_verification_hit(page, hv_snips)
            if hv2:
                return ClaudeRunResult(
                    False,
                    "human_verification_blocked",
                    f"matched:{hv2!r} (post-send; Claude web anti-bot)",
                )

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
