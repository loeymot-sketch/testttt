"""
Resolves the next browser-bridge action from cycle FSM + supervisor paths + session.

No daemons, no Playwright import — output is JSON for operator or future runner.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from bot.browser_bridge import claude_browser_bridge as cbb
from bot.browser_bridge import cursor_browser_bridge as cub
from bot.browser_bridge.session_store import BrowserBridgeSession, SessionStore
from bot.runtime.init import RuntimePaths, load_json_file, resolve_repo_root
from bot.runtime.state_manager import StateManager
from bot.supervisor import SupervisorConfig, load_supervisor_config


NEXT_ACTION_FILENAME = "browser_bridge_next_action.json"


@dataclass(frozen=True)
class BridgePaths:
    repo_root: Path
    session_file: Path
    next_action_file: Path


def bridge_paths_from_rt(rt: RuntimePaths) -> BridgePaths:
    state_dir = rt.state_dir
    return BridgePaths(
        repo_root=rt.repo_root,
        session_file=state_dir / "browser_bridge_session.json",
        next_action_file=state_dir / NEXT_ACTION_FILENAME,
    )


def build_controller(bot_config_path: Path) -> tuple[BridgePaths, StateManager, SupervisorConfig, SessionStore]:
    cfg = load_json_file(bot_config_path)
    repo = resolve_repo_root(cfg, bot_config_path)
    rt = RuntimePaths.from_bot_config(repo, cfg)
    bp = bridge_paths_from_rt(rt)
    sm = StateManager(rt.cycle_state_file)
    bot_dir = bot_config_path.parent.parent  # bot/config -> bot
    sup = load_supervisor_config(repo, bot_dir)
    store = SessionStore(bp.session_file)
    return bp, sm, sup, store


def resolve_next_action_dict(
    state: Any,
    sup: SupervisorConfig,
    bp: BridgePaths,
    session: BrowserBridgeSession,
) -> dict[str, Any]:
    """Single deterministic structure for CLI and file export."""
    blockers: list[str] = []
    base: dict[str, Any] = {
        "schema_version": 1,
        "cycle_id": state.cycle_id,
        "fsm_state": state.state,
        "claude_round": state.claude_round,
        "task_id": state.task_id,
        "paused": session.paused,
        "pause_reason": session.pause_reason,
        "action_kind": "noop",
        "actor": "none",
        "instructions": {},
        "supervisor_command": "python bot/cli.py run-supervisor-once",
        "paths": {
            "outbox_claude": str(sup.outbox_claude.resolve()),
            "outbox_cursor": str(sup.outbox_cursor.resolve()),
            "inbox_claude_plan": str(sup.inbox_claude_plan.resolve()),
            "inbox_claude_review": str(sup.inbox_claude_review.resolve()),
            "inbox_cursor_result": str(sup.inbox_cursor_result.resolve()),
        },
        "blockers": blockers,
    }

    if session.paused:
        base["action_kind"] = "paused"
        base["actor"] = "human"
        base["instructions"] = {
            "message": "Bridge session paused; clear paused in browser_bridge_session.json or via future resume command.",
        }
        return base

    if state.state in (
        "idle",
        "completed",
        "blocked",
        "manual_gate",
        "waiting_playwright",
        "preparing_intake",
    ):
        base["action_kind"] = (
            "playwright_pending"
            if state.state == "waiting_playwright"
            else "terminal_or_idle"
        )
        base["actor"] = "human"
        msg = f"FSM state {state.state!r} — no Claude/Cursor browser paste step."
        if state.state == "waiting_playwright":
            msg = (
                "waiting_playwright — browser bridge does not drive Playwright; "
                "run E2E per plan then register-playwright-result or inject antigravity report."
            )
        elif state.state == "preparing_intake":
            msg = "preparing_intake — wait for intake/handoff artifacts; no browser action yet."
        base["instructions"] = {"message": msg}
        return base

    cstep = cbb.resolve_claude_step(state)
    if cstep is not None:
        handoff = sup.outbox_claude / cstep.outbox_handoff_filename
        inbox = cbb.inbox_dir_for_step(
            sup.inbox_claude_plan, sup.inbox_claude_review, cstep
        )
        base["action_kind"] = "claude_project_browser"
        base["actor"] = "claude_browser"
        base["instructions"] = {
            "conversation_label": session.claude_conversation_label
            or cbb.CLAUDE_CONVERSATION_DEFAULT,
            "paste_source_file": str(handoff.resolve()),
            "handoff_must_exist": handoff.is_file(),
            "drop_json_directory": str(inbox.resolve()),
            "suggested_filename": cstep.suggested_inbox_filename,
            "expected_response_kind": cstep.required_json_response_kind,
            "must_match_cycle_id": state.cycle_id,
            "must_match_task_id": state.task_id or "",
            "success_detection": "Valid JSON file appears in drop_json_directory; supervisor run_once ingests first *.json by name order.",
            "malformed_detection": "Non-JSON, fenced markdown, wrong response_kind, or cycle_id mismatch - see failure_modes.md",
            "retry_policy": "Fix file in inbox or delete stray JSON; run-supervisor-once again; never duplicate cycle_id in two active files.",
        }
        if not handoff.is_file():
            blockers.append(f"missing_handoff:{handoff}")
        base["blockers"] = blockers
        return base

    ustep = cub.resolve_cursor_step(state)
    if ustep is not None and ustep.phase == "execute":
        handoff = sup.outbox_cursor / ustep.outbox_handoff_filename
        inbox = sup.inbox_cursor_result
        base["action_kind"] = "cursor_agent"
        base["actor"] = "cursor"
        base["instructions"] = {
            "session_hint": session.cursor_session_label or "(set cursor_session_label in browser_bridge_session.json)",
            "paste_source_file": str(handoff.resolve()),
            "handoff_must_exist": handoff.is_file(),
            "produce_file": str((inbox / ustep.cursor_done_filename).resolve()),
            "expected_kind": "cursor_done",
            "must_match_cycle_id": state.cycle_id,
            "success_detection": f"File {ustep.cursor_done_filename} appears with valid kind and cycle_id; then run-supervisor-once.",
            "blocked_detection": "Cursor cannot complete — write cursor_done.json with status blocked and summary (convention; supervisor still expects kind cursor_done today — use execution report + human gate if schema extended later).",
        }
        if not handoff.is_file():
            blockers.append(f"missing_handoff:{handoff}")
        base["blockers"] = blockers
        return base

    if ustep is not None and ustep.phase == "validate":
        inbox = sup.inbox_cursor_result
        vpath = inbox / ustep.validation_filename
        base["action_kind"] = "local_validation"
        base["actor"] = "human_or_ci"
        base["instructions"] = {
            "produce_file": str(vpath.resolve()),
            "expected_kind": "validation_result",
            "must_match_cycle_id": state.cycle_id,
            "allowed_status": ["passed", "failed", "skipped"],
            "warning": "status=failed transitions FSM to blocked per CycleController rules.",
            "success_detection": "validation.json present and valid; run-supervisor-once.",
        }
        base["blockers"] = blockers
        return base

    base["action_kind"] = "unknown_fsm_wait"
    base["actor"] = "human"
    base["instructions"] = {
        "message": "No Claude or Cursor bridge step mapped for this FSM state.",
    }
    base["blockers"] = blockers
    return base


def cmd_status(bot_config_path: Path) -> None:
    bp, sm, sup, store = build_controller(bot_config_path)
    state = sm.read()
    session = store.read()
    out = {
        "session": session.to_json_dict(),
        "cycle_state": state.to_json_dict(),
        "supervisor_outbox_claude": str(sup.outbox_claude.resolve()),
        "next_action_file": str(bp.next_action_file.resolve()),
    }
    print(json.dumps(out, indent=2, ensure_ascii=False))


def cmd_prepare(bot_config_path: Path) -> None:
    bp, sm, sup, store = build_controller(bot_config_path)
    state = sm.read()
    session = store.read()
    session.last_cycle_id = state.cycle_id or session.last_cycle_id
    store.write(session)
    bp.next_action_file.parent.mkdir(parents=True, exist_ok=True)
    action = resolve_next_action_dict(state, sup, bp, session)
    bp.next_action_file.write_text(
        json.dumps(action, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    print(str(bp.next_action_file.resolve()))


def cmd_next_action(bot_config_path: Path) -> None:
    bp, sm, sup, store = build_controller(bot_config_path)
    state = sm.read()
    session = store.read()
    session.last_cycle_id = state.cycle_id or session.last_cycle_id
    session.last_action_kind = "resolve_next_action"
    store.write(session)
    action = resolve_next_action_dict(state, sup, bp, session)
    text = json.dumps(action, indent=2, ensure_ascii=False) + "\n"
    print(text, end="")
    bp.next_action_file.parent.mkdir(parents=True, exist_ok=True)
    bp.next_action_file.write_text(text, encoding="utf-8")
