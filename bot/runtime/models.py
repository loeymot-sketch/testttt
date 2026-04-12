"""Typed structures for bot v0 JSON packets and cycle state."""

from __future__ import annotations

from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from enum import Enum
from typing import Any, Mapping


def _utc_now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


class CyclePhase(str, Enum):
    """Single active cycle; terminal / hold states explicit."""

    IDLE = "idle"
    PREPARING_INTAKE = "preparing_intake"
    WAITING_CLAUDE = "waiting_claude"
    WAITING_CURSOR = "waiting_cursor"
    WAITING_VALIDATION = "waiting_validation"
    WAITING_PLAYWRIGHT = "waiting_playwright"
    COMPLETED = "completed"
    BLOCKED = "blocked"
    MANUAL_GATE = "manual_gate"


class ValidationStatus(str, Enum):
    PENDING = "pending"
    PASSED = "passed"
    FAILED = "failed"
    SKIPPED = "skipped"


class PlaywrightStatus(str, Enum):
    PENDING = "pending"
    SKIPPED = "skipped"
    WAITING = "waiting"
    PASSED = "passed"
    FAILED = "failed"


@dataclass(frozen=True)
class ModelRoute:
    """Subset of model_routing.json for one resolved choice."""

    tier: str
    provider: str
    model: str
    notes: str = ""

    @classmethod
    def from_mapping(cls, m: Mapping[str, Any]) -> ModelRoute:
        return cls(
            tier=str(m.get("tier", "default")),
            provider=str(m.get("provider", "placeholder")),
            model=str(m.get("model", "placeholder")),
            notes=str(m.get("notes", "")),
        )

    def to_json_dict(self) -> dict[str, Any]:
        return {
            "tier": self.tier,
            "provider": self.provider,
            "model": self.model,
            "notes": self.notes,
        }


@dataclass
class CycleState:
    """Persisted in bot/state/cycle_state.json (one cycle at a time)."""

    schema_version: int = 1
    cycle_id: str = ""
    state: str = CyclePhase.IDLE.value
    task_id: str | None = None
    created_at: str = field(default_factory=_utc_now_iso)
    updated_at: str = field(default_factory=_utc_now_iso)
    validation_status: str = ValidationStatus.PENDING.value
    validation_detail: str = ""
    playwright_status: str = PlaywrightStatus.SKIPPED.value
    playwright_detail: str = ""
    block_reason: str | None = None
    manual_gate_reason: str | None = None
    active_model_route: dict[str, Any] | None = None
    claude_round: str = "plan"

    def touch(self) -> None:
        self.updated_at = _utc_now_iso()

    def to_json_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_json_dict(cls, data: Mapping[str, Any]) -> CycleState:
        return cls(
            schema_version=int(data.get("schema_version", 1)),
            cycle_id=str(data.get("cycle_id", "")),
            state=str(data.get("state", CyclePhase.IDLE.value)),
            task_id=data.get("task_id"),
            created_at=str(data.get("created_at", _utc_now_iso())),
            updated_at=str(data.get("updated_at", _utc_now_iso())),
            validation_status=str(
                data.get("validation_status", ValidationStatus.PENDING.value)
            ),
            validation_detail=str(data.get("validation_detail", "")),
            playwright_status=str(
                data.get("playwright_status", PlaywrightStatus.SKIPPED.value)
            ),
            playwright_detail=str(data.get("playwright_detail", "")),
            block_reason=data.get("block_reason"),
            manual_gate_reason=data.get("manual_gate_reason"),
            active_model_route=data.get("active_model_route"),
            claude_round=str(data.get("claude_round", "plan")),
        )


@dataclass
class IntakeSection:
    title: str
    source_path: str
    body: str
    truncated: bool = False

    def to_json_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_json_dict(cls, data: Mapping[str, Any]) -> IntakeSection:
        return cls(
            title=str(data["title"]),
            source_path=str(data["source_path"]),
            body=str(data["body"]),
            truncated=bool(data.get("truncated", False)),
        )


@dataclass
class ClaudeIntakePacket:
    """Written by intake_builder; consumed by humans or future Claude API layer."""

    schema_version: int = 1
    cycle_id: str = ""
    task_id: str = ""
    trigger_source: str = "human"
    human_goal: str = ""
    built_at: str = field(default_factory=_utc_now_iso)
    model_route: dict[str, Any] = field(default_factory=dict)
    sections: list[IntakeSection] = field(default_factory=list)

    def to_json_dict(self) -> dict[str, Any]:
        return {
            "schema_version": self.schema_version,
            "cycle_id": self.cycle_id,
            "task_id": self.task_id,
            "trigger_source": self.trigger_source,
            "human_goal": self.human_goal,
            "built_at": self.built_at,
            "model_route": dict(self.model_route),
            "sections": [s.to_json_dict() for s in self.sections],
        }

    @classmethod
    def from_json_dict(cls, data: Mapping[str, Any]) -> ClaudeIntakePacket:
        secs = [
            IntakeSection.from_json_dict(x)
            for x in data.get("sections", [])
            if isinstance(x, Mapping)
        ]
        return cls(
            schema_version=int(data.get("schema_version", 1)),
            cycle_id=str(data.get("cycle_id", "")),
            task_id=str(data.get("task_id", "")),
            trigger_source=str(data.get("trigger_source", "human")),
            human_goal=str(data.get("human_goal", "")),
            built_at=str(data.get("built_at", _utc_now_iso())),
            model_route=dict(data.get("model_route") or {}),
            sections=secs,
        )


@dataclass
class ClaudeResponsePacket:
    """Plan or review payload after human or future API records Claude output."""

    schema_version: int = 1
    cycle_id: str = ""
    task_id: str = ""
    response_kind: str = "plan"
    received_at: str = field(default_factory=_utc_now_iso)
    objective: str = ""
    scope_non_goals: list[str] = field(default_factory=list)
    risk_class: str = "unknown"
    suggested_next_actor: str = "cursor_execute"
    test_stance: str = "Kimi-test"
    verdict: str | None = None
    human_decision: str | None = None
    files_allowed: list[str] = field(default_factory=list)

    def to_json_dict(self) -> dict[str, Any]:
        return {
            "schema_version": self.schema_version,
            "cycle_id": self.cycle_id,
            "task_id": self.task_id,
            "response_kind": self.response_kind,
            "received_at": self.received_at,
            "objective": self.objective,
            "scope_non_goals": list(self.scope_non_goals),
            "risk_class": self.risk_class,
            "suggested_next_actor": self.suggested_next_actor,
            "test_stance": self.test_stance,
            "verdict": self.verdict,
            "human_decision": self.human_decision,
            "files_allowed": list(self.files_allowed),
        }

    @classmethod
    def from_json_dict(cls, data: Mapping[str, Any]) -> ClaudeResponsePacket:
        return cls(
            schema_version=int(data.get("schema_version", 1)),
            cycle_id=str(data.get("cycle_id", "")),
            task_id=str(data.get("task_id", "")),
            response_kind=str(data.get("response_kind", "plan")),
            received_at=str(data.get("received_at", _utc_now_iso())),
            objective=str(data.get("objective", "")),
            scope_non_goals=list(data.get("scope_non_goals") or []),
            risk_class=str(data.get("risk_class", "unknown")),
            suggested_next_actor=str(
                data.get("suggested_next_actor", "cursor_execute")
            ),
            test_stance=str(data.get("test_stance", "Kimi-test")),
            verdict=data.get("verdict"),
            human_decision=data.get("human_decision"),
            files_allowed=list(data.get("files_allowed") or []),
        )


@dataclass
class CursorExecutionPacket:
    """Handoff for Cursor; no CLI invocation in v0."""

    schema_version: int = 1
    cycle_id: str = ""
    task_id: str = ""
    planning_path: str = "reports/planning/latest.md"
    files_allowed: list[str] = field(default_factory=list)
    validation_commands: list[str] = field(default_factory=list)
    prepared_at: str = field(default_factory=_utc_now_iso)

    def to_json_dict(self) -> dict[str, Any]:
        return {
            "schema_version": self.schema_version,
            "cycle_id": self.cycle_id,
            "task_id": self.task_id,
            "planning_path": self.planning_path,
            "files_allowed": list(self.files_allowed),
            "validation_commands": list(self.validation_commands),
            "prepared_at": self.prepared_at,
        }

    @classmethod
    def from_json_dict(cls, data: Mapping[str, Any]) -> CursorExecutionPacket:
        return cls(
            schema_version=int(data.get("schema_version", 1)),
            cycle_id=str(data.get("cycle_id", "")),
            task_id=str(data.get("task_id", "")),
            planning_path=str(
                data.get("planning_path", "reports/planning/latest.md")
            ),
            files_allowed=list(data.get("files_allowed") or []),
            validation_commands=list(data.get("validation_commands") or []),
            prepared_at=str(data.get("prepared_at", _utc_now_iso())),
        )
