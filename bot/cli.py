#!/usr/bin/env python3
"""
FoodKing bot v0 — local operator CLI (file-based only).

Run from repository root:
  PYTHONPATH=. python bot/cli.py <command> [options]

No Claude API, Cursor API, Telegram, Git, browser, or daemons.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any, Sequence

from bot.runtime.cycle_controller import CycleController, CycleStateError
from bot.runtime.handoff_manager import HandoffManager
from bot.runtime.init import (
    RuntimePaths,
    load_json_file,
    resolve_repo_root,
)
from bot.runtime.intake_builder import IntakeBuilder
from bot.runtime.models import ClaudeResponsePacket, PlaywrightStatus, ValidationStatus
from bot.runtime.state_manager import StateManager


def _bot_dir() -> Path:
    return Path(__file__).resolve().parent


def _default_bot_config_path() -> Path:
    return _bot_dir() / "config" / "bot_config.json"


def _load_bot_config_path(cli_arg: str | None) -> Path:
    if cli_arg:
        return Path(cli_arg).expanduser().resolve()
    return _default_bot_config_path()


def _build_controller(bot_config_path: Path) -> tuple[CycleController, CycleState, RuntimePaths]:
    cfg = load_json_file(bot_config_path)
    if not cfg:
        raise SystemExit(
            f"Missing or empty bot config: {bot_config_path}\n"
            "Copy bot/config/bot_config.example.json to bot/config/bot_config.json if needed."
        )
    repo = resolve_repo_root(cfg, bot_config_path)
    rt = RuntimePaths.from_bot_config(repo, cfg)
    sm = StateManager(rt.cycle_state_file)
    hm = HandoffManager(rt.state_dir)
    ib = IntakeBuilder(rt)
    ctrl = CycleController(rt, sm, hm, ib)
    return ctrl, sm.read(), rt


def _die(msg: str, code: int = 1) -> None:
    print(msg, file=sys.stderr)
    raise SystemExit(code)


def _load_json_file_arg(path_str: str) -> dict[str, Any]:
    p = Path(path_str)
    if not p.is_file():
        _die(f"File not found: {p}")
    try:
        data = json.loads(p.read_text(encoding="utf-8"))
    except json.JSONDecodeError as e:
        _die(f"Invalid JSON in {p}: {e}")
    if not isinstance(data, dict):
        _die(f"JSON root must be an object: {p}")
    return data


def cmd_begin_cycle(args: argparse.Namespace) -> None:
    ctrl, before, rt = _build_controller(_load_bot_config_path(args.config))
    if before.state not in ("idle", "completed"):
        _die(
            f"Refusing begin-cycle: current state is {before.state!r}. "
            f"Run `reset-idle` first if you intend to abandon the active cycle."
        )
    s = ctrl.begin_cycle(
        task_id=args.task_id,
        human_goal=args.goal,
        trigger_source=args.trigger,
    )
    intake_path = rt.state_dir / "handoffs" / s.cycle_id / HandoffManager.INTAKE_NAME
    print(json.dumps(s.to_json_dict(), indent=2, ensure_ascii=False))
    print(f"\nClaude intake written: {intake_path}", file=sys.stderr)


def cmd_show_state(args: argparse.Namespace) -> None:
    _, s, _ = _build_controller(_load_bot_config_path(args.config))
    print(json.dumps(s.to_json_dict(), indent=2, ensure_ascii=False))


def cmd_register_claude_response(args: argparse.Namespace) -> None:
    ctrl, _, _ = _build_controller(_load_bot_config_path(args.config))
    data = _load_json_file_arg(args.file)
    packet = ClaudeResponsePacket.from_json_dict(data)
    if packet.response_kind != "plan":
        _die("register-claude-response: JSON must have response_kind: plan")
    try:
        s = ctrl.register_claude_plan_response(packet)
    except CycleStateError as e:
        _die(str(e))
    print(json.dumps(s.to_json_dict(), indent=2, ensure_ascii=False))


def cmd_register_claude_review(args: argparse.Namespace) -> None:
    ctrl, _, _ = _build_controller(_load_bot_config_path(args.config))
    data = _load_json_file_arg(args.file)
    packet = ClaudeResponsePacket.from_json_dict(data)
    if packet.response_kind != "review":
        _die("register-claude-review: JSON must have response_kind: review")
    try:
        s = ctrl.register_claude_review_response(packet)
    except CycleStateError as e:
        _die(str(e))
    print(json.dumps(s.to_json_dict(), indent=2, ensure_ascii=False))


def cmd_register_cursor_finished(args: argparse.Namespace) -> None:
    ctrl, _, _ = _build_controller(_load_bot_config_path(args.config))
    try:
        s = ctrl.register_cursor_finished()
    except CycleStateError as e:
        _die(str(e))
    print(json.dumps(s.to_json_dict(), indent=2, ensure_ascii=False))


def cmd_register_validation_result(args: argparse.Namespace) -> None:
    ctrl, _, _ = _build_controller(_load_bot_config_path(args.config))
    try:
        st = ValidationStatus(args.status)
    except ValueError:
        _die(f"Unknown validation status: {args.status!r}")
    try:
        s = ctrl.register_validation_result(st, detail=args.detail or "")
    except CycleStateError as e:
        _die(str(e))
    print(json.dumps(s.to_json_dict(), indent=2, ensure_ascii=False))


def cmd_register_playwright_result(args: argparse.Namespace) -> None:
    ctrl, _, _ = _build_controller(_load_bot_config_path(args.config))
    try:
        st = PlaywrightStatus(args.status)
    except ValueError:
        _die(f"Unknown playwright status: {args.status!r}")
    try:
        s = ctrl.register_playwright_result(st, detail=args.detail or "")
    except CycleStateError as e:
        _die(str(e))
    print(json.dumps(s.to_json_dict(), indent=2, ensure_ascii=False))


def cmd_force_blocked(args: argparse.Namespace) -> None:
    ctrl, _, _ = _build_controller(_load_bot_config_path(args.config))
    s = ctrl.force_blocked(args.reason)
    print(json.dumps(s.to_json_dict(), indent=2, ensure_ascii=False))


def cmd_force_manual_gate(args: argparse.Namespace) -> None:
    ctrl, _, _ = _build_controller(_load_bot_config_path(args.config))
    s = ctrl.force_manual_gate(args.reason)
    print(json.dumps(s.to_json_dict(), indent=2, ensure_ascii=False))


def cmd_reset_idle(args: argparse.Namespace) -> None:
    ctrl, _, _ = _build_controller(_load_bot_config_path(args.config))
    s = ctrl.reset_to_idle()
    print(json.dumps(s.to_json_dict(), indent=2, ensure_ascii=False))


def _add_config_arg(p: argparse.ArgumentParser) -> None:
    p.add_argument(
        "--config",
        metavar="PATH",
        default=None,
        help="Path to bot_config.json (default: bot/config/bot_config.json next to this package)",
    )


def main(argv: Sequence[str] | None = None) -> None:
    argv = list(argv if argv is not None else sys.argv[1:])
    parser = argparse.ArgumentParser(
        prog="python bot/cli.py",
        description="FoodKing bot v0 — file-based cycle operator (no external APIs).",
    )
    sub = parser.add_subparsers(dest="command", required=True)

    p_begin = sub.add_parser("begin-cycle", help="Start a new cycle (writes intake, state=waiting_claude).")
    _add_config_arg(p_begin)
    p_begin.add_argument("--task-id", required=True)
    p_begin.add_argument("--goal", required=True)
    p_begin.add_argument("--trigger", default="human")
    p_begin.set_defaults(func=cmd_begin_cycle)

    p_show = sub.add_parser("show-state", help="Print cycle_state.json.")
    _add_config_arg(p_show)
    p_show.set_defaults(func=cmd_show_state)

    p_plan = sub.add_parser(
        "register-claude-response",
        help="Ingest Claude plan JSON (response_kind=plan).",
    )
    _add_config_arg(p_plan)
    p_plan.add_argument("--file", required=True, help="Path to JSON file.")
    p_plan.set_defaults(func=cmd_register_claude_response)

    p_cursor = sub.add_parser(
        "register-cursor-finished",
        help="Move waiting_cursor -> waiting_validation.",
    )
    _add_config_arg(p_cursor)
    p_cursor.set_defaults(func=cmd_register_cursor_finished)

    p_val = sub.add_parser(
        "register-validation-result",
        help="passed|failed|skipped (failed -> blocked; passed|skipped -> waiting_claude review).",
    )
    _add_config_arg(p_val)
    p_val.add_argument(
        "--status",
        required=True,
        choices=["passed", "failed", "skipped"],
    )
    p_val.add_argument("--detail", default="")
    p_val.set_defaults(func=cmd_register_validation_result)

    p_pw = sub.add_parser(
        "register-playwright-result",
        help="passed|failed|skipped (failed -> blocked; passed|skipped -> waiting_claude review).",
    )
    _add_config_arg(p_pw)
    p_pw.add_argument(
        "--status",
        required=True,
        choices=["passed", "failed", "skipped"],
    )
    p_pw.add_argument("--detail", default="")
    p_pw.set_defaults(func=cmd_register_playwright_result)

    p_rev = sub.add_parser(
        "register-claude-review",
        help="Ingest Claude review JSON (response_kind=review).",
    )
    _add_config_arg(p_rev)
    p_rev.add_argument("--file", required=True)
    p_rev.set_defaults(func=cmd_register_claude_review)

    p_blk = sub.add_parser("force-blocked", help="Set state to blocked with reason.")
    _add_config_arg(p_blk)
    p_blk.add_argument("--reason", required=True)
    p_blk.set_defaults(func=cmd_force_blocked)

    p_gate = sub.add_parser("force-manual-gate", help="Set state to manual_gate with reason.")
    _add_config_arg(p_gate)
    p_gate.add_argument("--reason", required=True)
    p_gate.set_defaults(func=cmd_force_manual_gate)

    p_rst = sub.add_parser("reset-idle", help="Reset cycle state machine to idle (new empty CycleState).")
    _add_config_arg(p_rst)
    p_rst.set_defaults(func=cmd_reset_idle)

    ns = parser.parse_args(argv)
    ns.func(ns)


if __name__ == "__main__":
    main()
