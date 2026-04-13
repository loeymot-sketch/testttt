"""
Single-step browser runner orchestration (no loops, no daemon).

Reads bot/state/browser_bridge_next_action.json (must exist — run browser-bridge-prepare).
"""

from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from typing import Any

from bot.browser_bridge.cursor_browser_bridge import (
    cursor_done_success_heuristic,
    validation_success_heuristic,
)
from bot.browser_runner import claude_runner, cursor_runner
from bot.browser_runner.json_extractor import (
    extract_cursor_done,
    extract_plan_or_review,
    extract_validation_result,
    validate_plan_shape,
    validate_review_shape,
)
from bot.runtime.cycle_controller import CycleController
from bot.runtime.handoff_manager import HandoffManager
from bot.runtime.init import RuntimePaths, load_json_file, resolve_repo_root
from bot.runtime.intake_builder import IntakeBuilder
from bot.runtime.state_manager import StateManager


def _build_cycle_controller(bot_config_path: Path) -> CycleController:
    cfg = load_json_file(bot_config_path)
    repo = resolve_repo_root(cfg, bot_config_path)
    rt = RuntimePaths.from_bot_config(repo, cfg)
    sm = StateManager(rt.cycle_state_file)
    hm = HandoffManager(rt.state_dir)
    ib = IntakeBuilder(rt)
    return CycleController(rt, sm, hm, ib)


def load_browser_profile(repo_root: Path) -> tuple[dict[str, Any], Path]:
    local = repo_root / "bot" / "browser_runner" / "browser_profiles.json"
    example = repo_root / "bot" / "browser_runner" / "browser_profiles.example.json"
    if local.is_file():
        data = load_json_file(local)
        return (data if isinstance(data, dict) else {}), local
    data = load_json_file(example)
    return (data if isinstance(data, dict) else {}), example


def read_next_action(state_dir: Path) -> dict[str, Any]:
    p = state_dir / "browser_bridge_next_action.json"
    if not p.is_file():
        raise FileNotFoundError(
            f"Missing {p}. Run: .\\bot-cli.ps1 browser-bridge-prepare (Windows) or PYTHONPATH=. python bot/cli.py browser-bridge-prepare"
        )
    data = load_json_file(p)
    if not isinstance(data, dict):
        raise ValueError("browser_bridge_next_action.json root must be object")
    return data


def atomic_write_json(path: Path, obj: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmpp = tempfile.mkstemp(suffix=".json", dir=str(path.parent))
    os.close(fd)
    tmp = Path(tmpp)
    try:
        tmp.write_text(
            json.dumps(obj, indent=2, ensure_ascii=False) + "\n",
            encoding="utf-8",
        )
        os.replace(tmp, path)
    finally:
        if tmp.exists():
            tmp.unlink(missing_ok=True)


def validate_inbox_json_matches_action(
    data: dict[str, Any], next_action: dict[str, Any]
) -> tuple[bool, str]:
    inst = next_action.get("instructions") or {}
    must_cid = str(inst.get("must_match_cycle_id", "") or next_action.get("cycle_id", ""))
    if must_cid and str(data.get("cycle_id", "")) != must_cid:
        return False, "cycle_id_mismatch"
    must_tid = str(inst.get("must_match_task_id", "") or "")
    if must_tid and str(data.get("task_id", "")) != must_tid:
        return False, "task_id_mismatch"
    return True, ""


def _blockers_payload(na: dict[str, Any]) -> tuple[bool, list[str]]:
    raw = na.get("blockers")
    if not raw:
        return False, []
    if not isinstance(raw, list):
        return True, [str(raw)]
    xs = [str(x) for x in raw if str(x).strip()]
    return bool(xs), xs


def run_step(
    bot_config_path: Path,
    *,
    headless: bool,
    write_inbox_on_success: bool,
) -> dict[str, Any]:
    cfg = load_json_file(bot_config_path)
    repo = resolve_repo_root(cfg, bot_config_path)
    rt = RuntimePaths.from_bot_config(repo, cfg)
    state_dir = rt.state_dir

    try:
        na = read_next_action(state_dir)
    except (OSError, ValueError) as e:
        return {"ok": False, "status": "config_error", "message": str(e)}

    kind = str(na.get("action_kind", ""))
    out: dict[str, Any] = {"ok": False, "status": "noop", "message": "", "action_kind": kind}

    if kind in ("paused", "terminal_or_idle", "noop"):
        out["status"] = kind
        out["message"] = str((na.get("instructions") or {}).get("message", "no browser step"))
        out["ok"] = kind == "terminal_or_idle"
        return out

    if kind == "unknown_fsm_wait":
        out["status"] = kind
        out["message"] = str((na.get("instructions") or {}).get("message", "no mapped step"))
        out["ok"] = False
        return out

    if kind == "local_validation":
        out["status"] = "local_validation_pending"
        out["message"] = "Run tests locally; write validation.json; then run-supervisor-once."
        out["ok"] = True
        return out

    if kind == "playwright_pending":
        out["status"] = "playwright_not_yet_automated"
        out["message"] = "Playwright for FoodKing app is not executed by browser_runner."
        out["ok"] = True
        return out

    profile, profile_path = load_browser_profile(repo)
    out["profile_source"] = str(profile_path)

    has_blockers, blockers = _blockers_payload(na)
    if has_blockers:
        out["status"] = "bridge_blockers"
        out["message"] = "; ".join(blockers)
        out["blockers"] = blockers
        return out

    if kind == "claude_project_browser":
        r = claude_runner.run_claude_browser_step(
            repo, profile, na, state_dir, headless=headless
        )
        out["claude"] = {
            "status": r.status,
            "message": r.message,
            "capture": str((state_dir / claude_runner.CAPTURE_FILENAME).resolve()),
        }
        if r.target_verification:
            out["claude"]["target_verification"] = r.target_verification
        if r.dom_diagnostics:
            out["claude"]["dom_diagnostics"] = r.dom_diagnostics
        if not r.ok:
            out["status"] = r.status
            out["message"] = r.message
            if r.status == "human_verification_blocked":
                ctrl = _build_cycle_controller(bot_config_path)
                transitioned, gate_note = ctrl.try_enter_manual_gate_for_claude_human_verification()
                out["fsm_transitioned_to_manual_gate"] = transitioned
                out["fsm_transition_note"] = gate_note
                if transitioned:
                    out["message"] = (
                        f"{r.message} | Cycle moved to manual_gate for operator handoff. "
                        "See bot/docs/CLAUDE_WEB_LIMITATION.md"
                    )
            return out
        cap = state_dir / claude_runner.CAPTURE_FILENAME
        raw = cap.read_text(encoding="utf-8")
        exp = str((na.get("instructions") or {}).get("expected_response_kind", "plan"))
        obj, err = extract_plan_or_review(raw, exp)
        if obj is None:
            out["status"] = "malformed_json"
            out["message"] = err
            return out
        okm, em = validate_inbox_json_matches_action(obj, na)
        if not okm:
            out["status"] = "validation_failed"
            out["message"] = em
            return out
        if write_inbox_on_success:
            inst = na.get("instructions") or {}
            drop = Path(str(inst.get("drop_json_directory", "")))
            name = str(inst.get("suggested_filename", "plan.json"))
            dest = drop / name
            atomic_write_json(dest, obj)
            out["wrote"] = str(dest.resolve())
        out["ok"] = True
        out["status"] = "success"
        out["message"] = (
            "Captured and extracted JSON"
            + ("; wrote inbox" if write_inbox_on_success else "; use browser-write-inbox to drop")
        )
        return out

    if kind == "cursor_agent":
        r = cursor_runner.run_cursor_agent_step(repo, profile, na, headless=headless)
        out["cursor"] = {"status": r.status, "message": r.message}
        inst = na.get("instructions") or {}
        cur = profile.get("cursor") or {}
        mode = str(cur.get("automation_mode", "clipboard")).lower()
        url = str(cur.get("start_url", "")).strip()

        if r.status == "human_must_complete_cursor":
            out["ok"] = True
            out["status"] = r.status
            out["message"] = r.message
            return out

        if not r.ok:
            out["status"] = r.status
            out["message"] = r.message
            return out

        if mode == "playwright" and url and r.status == "captured":
            cap = state_dir / claude_runner.CAPTURE_FILENAME
            raw = cap.read_text(encoding="utf-8")
            obj, err = extract_cursor_done(raw)
            if obj is None:
                out["status"] = "malformed_json"
                out["message"] = err
                return out
            okm, em = validate_inbox_json_matches_action(obj, na)
            if not okm:
                out["status"] = "validation_failed"
                out["message"] = em
                return out
            if write_inbox_on_success:
                dest = Path(str(inst.get("produce_file", "")))
                if not str(dest).strip() or not dest.name.strip():
                    out["status"] = "missing_produce_file"
                    out["message"] = "next_action.instructions.produce_file empty"
                    return out
                atomic_write_json(dest, obj)
                out["wrote"] = str(dest.resolve())
            out["ok"] = True
            out["status"] = "success"
            out["message"] = (
                "Captured Cursor reply; extracted cursor_done JSON"
                + ("; wrote inbox" if write_inbox_on_success else "; use browser-write-inbox")
            )
            return out

        out["ok"] = False
        out["status"] = r.status
        out["message"] = r.message
        return out

    out["status"] = "unsupported_action_kind"
    out["message"] = kind
    return out


def cmd_open_target(bot_config_path: Path, target: str, *, headless: bool) -> dict[str, Any]:
    cfg = load_json_file(bot_config_path)
    repo = resolve_repo_root(cfg, bot_config_path)
    profile, src = load_browser_profile(repo)
    if target == "claude":
        r = claude_runner.open_claude_target(repo, profile, headless=headless)
        return {"ok": r.ok, "status": r.status, "message": r.message, "profile_source": str(src)}
    if target == "cursor":
        r = cursor_runner.open_cursor_target(repo, profile, headless=headless)
        return {"ok": r.ok, "status": r.status, "message": r.message, "profile_source": str(src)}
    return {"ok": False, "status": "bad_target", "message": "use claude or cursor"}


def _parse_capture_text(
    raw: str,
    *,
    from_next_action: bool,
    state_dir: Path,
    parse_kind: str | None,
) -> tuple[dict[str, Any] | None, str, str]:
    """
    Returns (obj, err, resolved_kind).
    """
    if parse_kind:
        kind = parse_kind.strip().lower()
        if kind in ("plan", "review"):
            obj, err = extract_plan_or_review(raw, kind)
            return obj, err, kind
        if kind == "cursor_done":
            obj, err = extract_cursor_done(raw)
            return obj, err, kind
        if kind in ("validation", "validation_result"):
            obj, err = extract_validation_result(raw)
            return obj, err, "validation_result"
        return None, f"unknown_parse_kind:{parse_kind}", ""

    if not from_next_action:
        obj, err = extract_plan_or_review(raw, "plan")
        return obj, err, "plan"

    na = read_next_action(state_dir)
    inst = na.get("instructions") or {}
    ak = str(na.get("action_kind", ""))
    if ak == "claude_project_browser":
        k = str(inst.get("expected_response_kind", "plan"))
        obj, err = extract_plan_or_review(raw, k)
        return obj, err, k
    if ak == "cursor_agent":
        obj, err = extract_cursor_done(raw)
        return obj, err, "cursor_done"
    if ak == "local_validation":
        obj, err = extract_validation_result(raw)
        return obj, err, "validation_result"
    obj, err = extract_plan_or_review(raw, "plan")
    return obj, err, "plan"


def cmd_parse_last(
    bot_config_path: Path,
    *,
    from_next_action: bool,
    parse_kind: str | None,
) -> dict[str, Any]:
    cfg = load_json_file(bot_config_path)
    repo = resolve_repo_root(cfg, bot_config_path)
    rt = RuntimePaths.from_bot_config(repo, cfg)
    cap = rt.state_dir / claude_runner.CAPTURE_FILENAME
    if not cap.is_file():
        return {"ok": False, "status": "missing_capture", "message": str(cap)}
    raw = cap.read_text(encoding="utf-8")
    obj, err, rk = _parse_capture_text(
        raw, from_next_action=from_next_action, state_dir=rt.state_dir, parse_kind=parse_kind
    )
    if obj is None:
        return {"ok": False, "status": "parse_error", "message": err, "resolved_kind": rk}
    return {
        "ok": True,
        "status": "parsed",
        "resolved_kind": rk,
        "data": obj,
    }


def cmd_write_inbox(
    bot_config_path: Path, source_file: Path, *, dry_run: bool
) -> dict[str, Any]:
    cfg = load_json_file(bot_config_path)
    repo = resolve_repo_root(cfg, bot_config_path)
    rt = RuntimePaths.from_bot_config(repo, cfg)
    na = read_next_action(rt.state_dir)
    inst = na.get("instructions") or {}
    ak = str(na.get("action_kind", ""))

    try:
        data = json.loads(source_file.read_text(encoding="utf-8-sig"))
    except (OSError, json.JSONDecodeError) as e:
        return {"ok": False, "status": "invalid_json_file", "message": str(e)}
    if not isinstance(data, dict):
        return {"ok": False, "status": "invalid_root", "message": "must be object"}

    dest: Path | None = None
    if ak == "claude_project_browser":
        exp = str(inst.get("expected_response_kind", "plan"))
        if exp == "plan":
            ok, msg = validate_plan_shape(data)
        elif exp == "review":
            ok, msg = validate_review_shape(data)
        else:
            return {
                "ok": False,
                "status": "unsupported_expected_response_kind",
                "message": exp,
            }
        if not ok:
            return {"ok": False, "status": "shape_error", "message": msg}
        obj = data
        drop = Path(str(inst.get("drop_json_directory", "")))
        name = str(inst.get("suggested_filename", "plan.json"))
        if not drop or not name:
            return {
                "ok": False,
                "status": "bad_next_action",
                "message": "missing drop_json_directory or suggested_filename",
            }
        dest = drop / name
    elif ak == "cursor_agent" and str(inst.get("expected_kind", "")).lower() == "cursor_done":
        ok, msg = cursor_done_success_heuristic(data)
        if not ok:
            return {"ok": False, "status": "shape_error", "message": msg}
        obj = data
        dest = Path(str(inst.get("produce_file", "")))
        if not str(dest).strip() or not dest.name.strip():
            return {"ok": False, "status": "bad_next_action", "message": "missing produce_file"}
    elif ak == "local_validation":
        ok, msg = validation_success_heuristic(data)
        if not ok:
            return {"ok": False, "status": "shape_error", "message": msg}
        obj = data
        dest = Path(str(inst.get("produce_file", "")))
        if not str(dest).strip() or not dest.name.strip():
            return {"ok": False, "status": "bad_next_action", "message": "missing produce_file"}
    else:
        return {
            "ok": False,
            "status": "unsupported_action_kind",
            "message": ak,
        }

    okm, em = validate_inbox_json_matches_action(obj, na)
    if not okm:
        return {"ok": False, "status": "cycle_guard", "message": em}

    assert dest is not None
    if dry_run:
        return {"ok": True, "status": "dry_run", "would_write": str(dest.resolve())}
    atomic_write_json(dest, obj)
    return {"ok": True, "status": "written", "path": str(dest.resolve())}
