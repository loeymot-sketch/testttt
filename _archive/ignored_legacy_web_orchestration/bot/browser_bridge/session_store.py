"""
Persistent bridge session (operator + automation hints), separate from cycle FSM.

Stored at: bot/state/browser_bridge_session.json
"""

from __future__ import annotations

import json
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Mapping


def _utc_now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


@dataclass
class BrowserBridgeSession:
    """Sidecar state for browser automation; never replaces cycle_state.json."""

    schema_version: int = 1
    updated_at: str = field(default_factory=_utc_now_iso)
    last_cycle_id: str = ""
    claude_conversation_label: str = "00_ORCHESTRATOR"
    cursor_session_label: str = ""
    paused: bool = False
    pause_reason: str = ""
    last_action_kind: str = ""
    last_error_code: str = ""
    notes: str = ""

    def touch(self) -> None:
        self.updated_at = _utc_now_iso()

    def to_json_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_json_dict(cls, data: Mapping[str, Any]) -> BrowserBridgeSession:
        return cls(
            schema_version=int(data.get("schema_version", 1)),
            updated_at=str(data.get("updated_at", _utc_now_iso())),
            last_cycle_id=str(data.get("last_cycle_id", "")),
            claude_conversation_label=str(
                data.get("claude_conversation_label", "00_ORCHESTRATOR")
            ),
            cursor_session_label=str(data.get("cursor_session_label", "")),
            paused=bool(data.get("paused", False)),
            pause_reason=str(data.get("pause_reason", "")),
            last_action_kind=str(data.get("last_action_kind", "")),
            last_error_code=str(data.get("last_error_code", "")),
            notes=str(data.get("notes", "")),
        )


class SessionStore:
    def __init__(self, path: Path) -> None:
        self._path = path

    def read(self) -> BrowserBridgeSession:
        if not self._path.is_file():
            return BrowserBridgeSession()
        try:
            raw = json.loads(self._path.read_text(encoding="utf-8-sig"))
        except (json.JSONDecodeError, OSError):
            return BrowserBridgeSession()
        if not isinstance(raw, dict):
            return BrowserBridgeSession()
        return BrowserBridgeSession.from_json_dict(raw)

    def write(self, session: BrowserBridgeSession) -> None:
        session.touch()
        self._path.parent.mkdir(parents=True, exist_ok=True)
        self._path.write_text(
            json.dumps(session.to_json_dict(), indent=2, ensure_ascii=False) + "\n",
            encoding="utf-8",
        )
