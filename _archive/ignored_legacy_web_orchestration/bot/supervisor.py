"""
Local cycle supervisor — one tick (poll inbox, refresh outbox, at most one transition).

File-based only: no browser, no APIs, no Telegram, no Git.
"""

from __future__ import annotations

import json
import shutil
import sys
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Mapping

from bot.runtime.cycle_controller import CycleController, CycleStateError
from bot.runtime.handoff_manager import HandoffManager
from bot.runtime.init import RuntimePaths, load_json_file, resolve_repo_root
from bot.runtime.intake_builder import IntakeBuilder
from bot.runtime.models import ClaudeResponsePacket, CycleState, ValidationStatus
from bot.runtime.prompt_compiler import PromptCompiler, PromptCompilerError
from bot.runtime.review_bridge import ReviewBridge, ReviewBridgeError
from bot.runtime.state_manager import StateManager


class SupervisorError(RuntimeError):
    """Invalid configuration, JSON, or cycle guard."""


@dataclass(frozen=True)
class SupervisorConfig:
    enabled: bool
    inbox_claude_plan: Path
    inbox_claude_review: Path
    inbox_cursor_result: Path
    outbox_claude: Path
    outbox_cursor: Path
    archive_subdir: str
    cursor_done_filename: str
    validation_filename: str
    outbox_claude_handoff_name: str
    outbox_claude_review_name: str
    outbox_cursor_handoff_name: str


def _utc_stamp() -> str:
    return datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")


def load_supervisor_config(repo_root: Path, bot_dir: Path) -> SupervisorConfig:
    p = bot_dir / "config" / "supervisor.json"
    raw = load_json_file(p)
    if not raw.get("enabled", True):
        raise SupervisorError("supervisor.json has enabled=false.")
    def _rel(key: str, default: str) -> Path:
        s = str(raw.get(key, default) or default)
        path = Path(s)
        return path if path.is_absolute() else (repo_root / path)

    return SupervisorConfig(
        enabled=bool(raw.get("enabled", True)),
        inbox_claude_plan=_rel("inbox_claude_plan", "bot/inbox/claude_plan"),
        inbox_claude_review=_rel("inbox_claude_review", "bot/inbox/claude_review"),
        inbox_cursor_result=_rel("inbox_cursor_result", "bot/inbox/cursor_result"),
        outbox_claude=_rel("outbox_claude", "bot/outbox/claude"),
        outbox_cursor=_rel("outbox_cursor", "bot/outbox/cursor"),
        archive_subdir=str(raw.get("archive_subdir", "supervisor_inbox_archive")),
        cursor_done_filename=str(raw.get("cursor_done_filename", "cursor_done.json")),
        validation_filename=str(raw.get("validation_filename", "validation.json")),
        outbox_claude_handoff_name=str(
            raw.get("outbox_claude_handoff_name", "claude_handoff.md")
        ),
        outbox_claude_review_name=str(
            raw.get("outbox_claude_review_name", "claude_review_handoff.md")
        ),
        outbox_cursor_handoff_name=str(
            raw.get("outbox_cursor_handoff_name", "cursor_handoff.md")
        ),
    )


def _ensure_dirs(cfg: SupervisorConfig) -> None:
    for d in (
        cfg.inbox_claude_plan,
        cfg.inbox_claude_review,
        cfg.inbox_cursor_result,
        cfg.outbox_claude,
        cfg.outbox_cursor,
    ):
        d.mkdir(parents=True, exist_ok=True)


def _build_controller(bot_config_path: Path) -> tuple[CycleController, CycleState, RuntimePaths]:
    cfg = load_json_file(bot_config_path)
    if not cfg:
        raise SupervisorError(f"Missing or empty bot config: {bot_config_path}")
    repo = resolve_repo_root(cfg, bot_config_path)
    rt = RuntimePaths.from_bot_config(repo, cfg)
    sm = StateManager(rt.cycle_state_file)
    hm = HandoffManager(rt.state_dir)
    ib = IntakeBuilder(rt)
    ctrl = CycleController(rt, sm, hm, ib)
    return ctrl, sm.read(), rt


def _archive_path(handoff_dir: Path, cfg: SupervisorConfig) -> Path:
    d = handoff_dir / cfg.archive_subdir
    d.mkdir(parents=True, exist_ok=True)
    return d


def _safe_move_to_archive(src: Path, archive_dir: Path) -> Path:
    """Move src into archive with unique name; refuse if collision."""
    dest = archive_dir / f"{_utc_stamp()}_{src.name}"
    if dest.exists():
        raise SupervisorError(f"Refusing archive move: destination exists {dest}")
    shutil.move(str(src), str(dest))
    return dest


def _copy_if_changed(src: Path, dest: Path) -> bool:
    """Copy src to dest if dest missing or content differs. Returns True if wrote."""
    data = src.read_bytes()
    if dest.is_file():
        if dest.read_bytes() == data:
            return False
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_bytes(data)
    return True


def _load_json(path: Path) -> dict[str, Any]:
    try:
        data = json.loads(path.read_text(encoding="utf-8-sig"))
    except json.JSONDecodeError as e:
        raise SupervisorError(f"Invalid JSON in {path}: {e}") from e
    if not isinstance(data, dict):
        raise SupervisorError(f"JSON root must be object: {path}")
    return data


def _sorted_json_files(d: Path) -> list[Path]:
    if not d.is_dir():
        return []
    return sorted(
        (
            p
            for p in d.iterdir()
            if p.is_file()
            and p.suffix.lower() == ".json"
            and not p.name.lower().endswith(".example.json")
        ),
        key=lambda p: p.name.lower(),
    )


def _first_plan_candidate(files: list[Path]) -> Path | None:
    """Deterministic: first *.json by name; skip obvious examples if present? No — operator must not leave stray json."""
    return files[0] if files else None


def run_once(bot_config_path: Path) -> int:
    """Single supervisor tick. Returns process exit code (0 ok, 1 error)."""
    bot_dir = Path(__file__).resolve().parent
    repo_root = bot_dir.parent
    try:
        cfg = load_supervisor_config(repo_root, bot_dir)
    except SupervisorError as e:
        print(f"Supervisor: {e}", file=sys.stderr)
        return 1
    _ensure_dirs(cfg)

    try:
        ctrl, state, rt = _build_controller(bot_config_path)
    except SupervisorError as e:
        print(f"Supervisor: {e}", file=sys.stderr)
        return 1

    if not state.cycle_id:
        print("Supervisor: idle (no cycle_id). Run begin-cycle first.")
        return 0

    hm = HandoffManager(rt.state_dir)
    handoff_dir = hm.handoff_dir(state.cycle_id)

    try:
        if state.state == "waiting_claude" and state.claude_round == "plan":
            return _tick_plan(ctrl, state, rt, hm, cfg, handoff_dir)
        if state.state == "waiting_cursor":
            return _tick_cursor(ctrl, state, rt, hm, cfg, handoff_dir)
        if state.state == "waiting_validation":
            return _tick_validation(ctrl, state, rt, cfg, handoff_dir)
        if state.state == "waiting_claude" and state.claude_round == "review":
            return _tick_review(ctrl, state, rt, hm, cfg, handoff_dir)
    except SupervisorError as e:
        print(f"Supervisor: ERROR: {e}", file=sys.stderr)
        return 1
    except CycleStateError as e:
        print(f"Supervisor: cycle error: {e}", file=sys.stderr)
        return 1

    print(
        f"Supervisor: no action for state={state.state!r} "
        f"claude_round={state.claude_round!r} (tick skipped)."
    )
    return 0


def _tick_plan(
    ctrl: CycleController,
    state: CycleState,
    rt: RuntimePaths,
    hm: HandoffManager,
    cfg: SupervisorConfig,
    handoff_dir: Path,
) -> int:
    pc = PromptCompiler(rt, hm)
    try:
        src = pc.write_claude_handoff(state)
    except PromptCompilerError as e:
        raise SupervisorError(str(e)) from e
    dest = cfg.outbox_claude / cfg.outbox_claude_handoff_name
    if _copy_if_changed(src, dest):
        print(f"Supervisor: refreshed outbox {dest}")

    candidates = _sorted_json_files(cfg.inbox_claude_plan)
    pick = _first_plan_candidate(candidates)
    if pick is None:
        print(
            f"Supervisor: waiting for plan JSON in {cfg.inbox_claude_plan} "
            f"(outbox ready: {dest})."
        )
        return 0

    data = _load_json(pick)
    pkt = ClaudeResponsePacket.from_json_dict(data)
    if pkt.response_kind != "plan":
        raise SupervisorError(f"{pick.name}: expected response_kind plan, got {pkt.response_kind!r}")
    if pkt.cycle_id != state.cycle_id:
        raise SupervisorError(
            f"{pick.name}: cycle_id mismatch (file={pkt.cycle_id!r} state={state.cycle_id!r})."
        )
    if state.task_id:
        if not (pkt.task_id or "").strip():
            raise SupervisorError(
                f"{pick.name}: task_id is required in JSON (active cycle has task_id={state.task_id!r})."
            )
        if pkt.task_id != state.task_id:
            raise SupervisorError(
                f"{pick.name}: task_id mismatch (file={pkt.task_id!r} state={state.task_id!r})."
            )

    ctrl.register_claude_plan_response(pkt)
    arc = _archive_path(handoff_dir, cfg) / "claude_plan"
    arc.mkdir(parents=True, exist_ok=True)
    moved = _safe_move_to_archive(pick, arc)
    print(f"Supervisor: registered plan from {pick.name} -> archived {moved}")
    return 0


def _tick_cursor(
    ctrl: CycleController,
    state: CycleState,
    rt: RuntimePaths,
    hm: HandoffManager,
    cfg: SupervisorConfig,
    handoff_dir: Path,
) -> int:
    pc = PromptCompiler(rt, hm)
    try:
        src = pc.write_cursor_handoff(state)
    except PromptCompilerError as e:
        raise SupervisorError(str(e)) from e
    dest = cfg.outbox_cursor / cfg.outbox_cursor_handoff_name
    if _copy_if_changed(src, dest):
        print(f"Supervisor: refreshed outbox {dest}")

    done_path = cfg.inbox_cursor_result / cfg.cursor_done_filename
    if not done_path.is_file():
        print(
            f"Supervisor: waiting for {cfg.cursor_done_filename} in {cfg.inbox_cursor_result}."
        )
        return 0

    data = _load_json(done_path)
    if str(data.get("kind", "")).lower() != "cursor_done":
        raise SupervisorError(
            f"{done_path.name}: expected kind \"cursor_done\", got {data.get('kind')!r}."
        )
    cid = str(data.get("cycle_id", ""))
    if not cid:
        raise SupervisorError(f"{done_path.name}: cycle_id is required.")
    if cid != state.cycle_id:
        raise SupervisorError(
            f"{done_path.name}: cycle_id mismatch (file={cid!r} state={state.cycle_id!r})."
        )

    ctrl.register_cursor_finished()
    arc = _archive_path(handoff_dir, cfg) / "cursor_result"
    arc.mkdir(parents=True, exist_ok=True)
    moved = _safe_move_to_archive(done_path, arc)
    print(f"Supervisor: cursor finished from {done_path.name} -> archived {moved}")
    return 0


def _tick_validation(
    ctrl: CycleController,
    state: CycleState,
    rt: RuntimePaths,
    cfg: SupervisorConfig,
    handoff_dir: Path,
) -> int:
    vpath = cfg.inbox_cursor_result / cfg.validation_filename
    if not vpath.is_file():
        print(
            f"Supervisor: waiting for {cfg.validation_filename} in {cfg.inbox_cursor_result}."
        )
        return 0

    data = _load_json(vpath)
    if str(data.get("kind", "")).lower() != "validation_result":
        raise SupervisorError(
            f"{vpath.name}: expected kind \"validation_result\", got {data.get('kind')!r}."
        )
    cid = str(data.get("cycle_id", ""))
    if not cid:
        raise SupervisorError(f"{vpath.name}: cycle_id is required.")
    if cid != state.cycle_id:
        raise SupervisorError(
            f"{vpath.name}: cycle_id mismatch (file={cid!r} state={state.cycle_id!r})."
        )
    st = str(data.get("status", "")).lower()
    if st not in ("passed", "failed", "skipped"):
        raise SupervisorError(f"{vpath.name}: status must be passed|failed|skipped, got {st!r}")
    detail = str(data.get("detail", "") or "")

    ctrl.register_validation_result(ValidationStatus(st), detail=detail)
    arc = _archive_path(handoff_dir, cfg) / "cursor_result"
    arc.mkdir(parents=True, exist_ok=True)
    moved = _safe_move_to_archive(vpath, arc)
    print(f"Supervisor: validation from {vpath.name} ({st}) -> archived {moved}")
    return 0


def _tick_review(
    ctrl: CycleController,
    state: CycleState,
    rt: RuntimePaths,
    hm: HandoffManager,
    cfg: SupervisorConfig,
    handoff_dir: Path,
) -> int:
    rb = ReviewBridge(rt, hm)
    try:
        src = rb.write_review_handoff(state)
    except ReviewBridgeError as e:
        raise SupervisorError(str(e)) from e
    dest = cfg.outbox_claude / cfg.outbox_claude_review_name
    if _copy_if_changed(src, dest):
        print(f"Supervisor: refreshed outbox {dest}")

    candidates = _sorted_json_files(cfg.inbox_claude_review)
    pick = _first_plan_candidate(candidates)
    if pick is None:
        print(
            f"Supervisor: waiting for review JSON in {cfg.inbox_claude_review} "
            f"(outbox ready: {dest})."
        )
        return 0

    data = _load_json(pick)
    pkt = ClaudeResponsePacket.from_json_dict(data)
    if pkt.response_kind != "review":
        raise SupervisorError(f"{pick.name}: expected response_kind review.")
    if pkt.cycle_id != state.cycle_id:
        raise SupervisorError(f"{pick.name}: cycle_id mismatch.")

    ctrl.register_claude_review_response(pkt)
    arc = _archive_path(handoff_dir, cfg) / "claude_review"
    arc.mkdir(parents=True, exist_ok=True)
    moved = _safe_move_to_archive(pick, arc)
    print(f"Supervisor: registered review from {pick.name} -> archived {moved}")
    return 0


def format_dropzone_report(repo_root: Path, bot_dir: Path) -> str:
    cfg = load_supervisor_config(repo_root, bot_dir)
    lines = [
        "FoodKing bot - supervisor dropzones (resolved paths)",
        f"  inbox claude_plan:    {cfg.inbox_claude_plan.resolve()}",
        f"  inbox claude_review:  {cfg.inbox_claude_review.resolve()}",
        f"  inbox cursor_result:  {cfg.inbox_cursor_result.resolve()}",
        f"  outbox claude:        {cfg.outbox_claude.resolve()}",
        f"  outbox cursor:        {cfg.outbox_cursor.resolve()}",
        f"  plan drop:            any *.json in claude_plan (one file per tick, first by name)",
        f"  review drop:          any *.json in claude_review",
        f"  cursor done:          {cfg.cursor_done_filename} in cursor_result",
        f"  validation:           {cfg.validation_filename} in cursor_result",
    ]
    return "\n".join(lines) + "\n"
