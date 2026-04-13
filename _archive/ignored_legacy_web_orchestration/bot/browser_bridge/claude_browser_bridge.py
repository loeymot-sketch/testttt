"""
Claude Project (browser) bridge — deterministic mapping from cycle FSM to files.

No network, no browser driver. Playbooks: claude_bridge_playbook.md
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Literal

from bot.runtime.models import CycleState


@dataclass(frozen=True)
class ClaudeBridgeStep:
    """One unit of work for Claude browser automation or human operator."""

    phase: Literal["plan", "review", "none"]
    outbox_handoff_filename: str
    inbox_dir_key: Literal["claude_plan", "claude_review"]
    suggested_inbox_filename: str
    conversation_label: str
    required_json_response_kind: str


CLAUDE_CONVERSATION_DEFAULT = "00_ORCHESTRATOR"


def resolve_claude_step(state: CycleState) -> ClaudeBridgeStep | None:
    """
    Return the active Claude browser step, or None if Claude browser is not waiting.
    """
    if state.state != "waiting_claude" or not state.cycle_id:
        return None
    rnd = (state.claude_round or "plan").strip().lower()
    if rnd == "plan":
        return ClaudeBridgeStep(
            phase="plan",
            outbox_handoff_filename="claude_handoff.md",
            inbox_dir_key="claude_plan",
            suggested_inbox_filename="plan.json",
            conversation_label=CLAUDE_CONVERSATION_DEFAULT,
            required_json_response_kind="plan",
        )
    if rnd == "review":
        return ClaudeBridgeStep(
            phase="review",
            outbox_handoff_filename="claude_review_handoff.md",
            inbox_dir_key="claude_review",
            suggested_inbox_filename="review.json",
            conversation_label=CLAUDE_CONVERSATION_DEFAULT,
            required_json_response_kind="review",
        )
    return None


def outbox_handoff_path(outbox_claude: Path, step: ClaudeBridgeStep) -> Path:
    return outbox_claude / step.outbox_handoff_filename


def inbox_dir_for_step(
    inbox_claude_plan: Path,
    inbox_claude_review: Path,
    step: ClaudeBridgeStep,
) -> Path:
    if step.inbox_dir_key == "claude_plan":
        return inbox_claude_plan
    return inbox_claude_review


def plan_json_success_heuristic(data: dict[str, Any]) -> tuple[bool, str]:
    """Structural checks only; orchestrator semantics are Claude's job."""
    if data.get("response_kind") != "plan":
        return False, 'response_kind must be "plan"'
    if not str(data.get("cycle_id", "")).strip():
        return False, "cycle_id is required"
    if data.get("verdict") is not None:
        return False, "plan packet must have verdict null"
    return True, ""


def review_json_success_heuristic(data: dict[str, Any]) -> tuple[bool, str]:
    if data.get("response_kind") != "review":
        return False, 'response_kind must be "review"'
    if not str(data.get("cycle_id", "")).strip():
        return False, "cycle_id is required"
    v = str(data.get("verdict", "")).upper()
    allowed = {"APPROVED", "NEEDS_FIX", "NEEDS_PLAYWRIGHT", "BLOCKED", "MANUAL_GATE"}
    if v not in allowed:
        return False, f"verdict must be one of {sorted(allowed)}"
    return True, ""


def detect_malformed_claude_json(
    raw_text: str, step: ClaudeBridgeStep
) -> tuple[bool, str]:
    """
    Return (is_malformed, reason). True if obviously not usable JSON root object.
    """
    t = raw_text.strip()
    if not t:
        return True, "empty body"
    if t.startswith("```"):
        return True, "markdown fence wrapper — strip fences before JSON parse"
    try:
        obj = json.loads(t)
    except json.JSONDecodeError as e:
        return True, f"json decode error: {e}"
    if not isinstance(obj, dict):
        return True, "json root must be object"
    if step.phase == "plan":
        ok, msg = plan_json_success_heuristic(obj)
        return not ok, msg or "plan heuristic failed"
    ok, msg = review_json_success_heuristic(obj)
    return not ok, msg or "review heuristic failed"
