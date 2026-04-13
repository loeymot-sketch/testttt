"""Persist handoff JSON packets under bot/state/handoffs/<cycle_id>/."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Mapping

from bot.runtime.models import ClaudeIntakePacket, ClaudeResponsePacket, CursorExecutionPacket


class HandoffManager:
    INTAKE_NAME = "claude_intake.json"
    RESPONSE_NAME = "claude_response.json"
    CURSOR_NAME = "cursor_execution.json"

    def __init__(self, state_dir: Path) -> None:
        self._state_dir = state_dir.resolve()
        self._state_dir.mkdir(parents=True, exist_ok=True)

    def handoff_dir(self, cycle_id: str) -> Path:
        return self._state_dir / "handoffs" / cycle_id

    def _ensure_handoff_dir(self, cycle_id: str) -> Path:
        d = self.handoff_dir(cycle_id)
        d.mkdir(parents=True, exist_ok=True)
        return d

    def save_claude_intake(self, cycle_id: str, packet: ClaudeIntakePacket) -> Path:
        path = self._ensure_handoff_dir(cycle_id) / self.INTAKE_NAME
        path.write_text(
            json.dumps(packet.to_json_dict(), indent=2, ensure_ascii=False),
            encoding="utf-8",
        )
        return path

    def load_claude_intake(self, cycle_id: str) -> ClaudeIntakePacket | None:
        path = self.handoff_dir(cycle_id) / self.INTAKE_NAME
        if not path.is_file():
            return None
        data: Mapping[str, Any] = json.loads(path.read_text(encoding="utf-8"))
        return ClaudeIntakePacket.from_json_dict(data)

    def save_claude_response(self, cycle_id: str, packet: ClaudeResponsePacket) -> Path:
        path = self._ensure_handoff_dir(cycle_id) / self.RESPONSE_NAME
        path.write_text(
            json.dumps(packet.to_json_dict(), indent=2, ensure_ascii=False),
            encoding="utf-8",
        )
        return path

    def load_claude_response(self, cycle_id: str) -> ClaudeResponsePacket | None:
        path = self.handoff_dir(cycle_id) / self.RESPONSE_NAME
        if not path.is_file():
            return None
        data: Mapping[str, Any] = json.loads(path.read_text(encoding="utf-8"))
        return ClaudeResponsePacket.from_json_dict(data)

    def save_cursor_execution(self, cycle_id: str, packet: CursorExecutionPacket) -> Path:
        path = self._ensure_handoff_dir(cycle_id) / self.CURSOR_NAME
        path.write_text(
            json.dumps(packet.to_json_dict(), indent=2, ensure_ascii=False),
            encoding="utf-8",
        )
        return path

    def load_cursor_execution(self, cycle_id: str) -> CursorExecutionPacket | None:
        path = self.handoff_dir(cycle_id) / self.CURSOR_NAME
        if not path.is_file():
            return None
        data: Mapping[str, Any] = json.loads(path.read_text(encoding="utf-8"))
        return CursorExecutionPacket.from_json_dict(data)
