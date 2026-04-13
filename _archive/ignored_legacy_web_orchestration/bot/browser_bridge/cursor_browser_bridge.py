"""
Cursor IDE (browser or desktop) bridge — deterministic mapping from cycle FSM.

No Cursor API. Playbooks: cursor_bridge_playbook.md
"""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any, Literal

from bot.runtime.models import CycleState


@dataclass(frozen=True)
class CursorBridgeStep:
    phase: Literal["execute", "validate", "none"]
    outbox_handoff_filename: str
    cursor_done_filename: str
    validation_filename: str


def resolve_cursor_step(state: CycleState) -> CursorBridgeStep | None:
    if not state.cycle_id:
        return None
    st = state.state
    if st == "waiting_cursor":
        return CursorBridgeStep(
            phase="execute",
            outbox_handoff_filename="cursor_handoff.md",
            cursor_done_filename="cursor_done.json",
            validation_filename="validation.json",
        )
    if st == "waiting_validation":
        return CursorBridgeStep(
            phase="validate",
            outbox_handoff_filename="",
            cursor_done_filename="cursor_done.json",
            validation_filename="validation.json",
        )
    return None


def cursor_outbox_handoff_path(outbox_cursor: Path, step: CursorBridgeStep) -> Path | None:
    if step.phase != "execute" or not step.outbox_handoff_filename:
        return None
    return outbox_cursor / step.outbox_handoff_filename


def cursor_done_expected_path(inbox_cursor: Path, filename: str) -> Path:
    return inbox_cursor / filename


def validation_expected_path(inbox_cursor: Path, filename: str) -> Path:
    return inbox_cursor / filename


def cursor_done_success_heuristic(data: dict[str, Any]) -> tuple[bool, str]:
    if str(data.get("kind", "")).lower() != "cursor_done":
        return False, 'kind must be "cursor_done"'
    if not str(data.get("cycle_id", "")).strip():
        return False, "cycle_id is required"
    return True, ""


def validation_success_heuristic(data: dict[str, Any]) -> tuple[bool, str]:
    if str(data.get("kind", "")).lower() != "validation_result":
        return False, 'kind must be "validation_result"'
    st = str(data.get("status", "")).lower()
    if st not in ("passed", "failed", "skipped"):
        return False, "status must be passed|failed|skipped"
    if not str(data.get("cycle_id", "")).strip():
        return False, "cycle_id is required"
    return True, ""


def detect_blocked_validation(data: dict[str, Any]) -> bool:
    return str(data.get("kind", "")).lower() == "validation_result" and str(
        data.get("status", "")
    ).lower() in ("failed",)
