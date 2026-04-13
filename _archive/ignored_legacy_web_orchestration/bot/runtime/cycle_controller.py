"""Deterministic cycle transitions (file-backed; one active cycle)."""

from __future__ import annotations

import uuid
from typing import Any, Mapping

from bot.runtime.handoff_manager import HandoffManager
from bot.runtime.init import (
    RuntimePaths,
    load_json_file,
    merge_paths_config,
    resolve_model_route,
)
from bot.runtime.intake_builder import IntakeBuilder
from bot.runtime.models import (
    ClaudeResponsePacket,
    CursorExecutionPacket,
    CyclePhase,
    CycleState,
    PlaywrightStatus,
    ValidationStatus,
)
from bot.runtime.state_manager import StateManager


class CycleStateError(ValueError):
    """Invalid transition or inconsistent cycle data."""


class CycleController:
    """
    Deterministic orchestration core.

    Callers must invoke public methods in a valid order; each method checks
    the current persisted state before writing.
    """

    def __init__(
        self,
        paths: RuntimePaths,
        state_manager: StateManager,
        handoff_manager: HandoffManager,
        intake_builder: IntakeBuilder,
    ) -> None:
        self._paths = paths
        self._state = state_manager
        self._handoffs = handoff_manager
        self._intake = intake_builder

    def placeholder_request_claude_api(self, *_args: Any, **_kwargs: Any) -> None:
        raise NotImplementedError(
            "Claude API integration is intentionally not implemented in v0."
        )

    def placeholder_send_telegram_alert(self, *_args: Any, **_kwargs: Any) -> None:
        raise NotImplementedError(
            "Telegram sending is intentionally not implemented in v0."
        )

    def placeholder_run_playwright(self, *_args: Any, **_kwargs: Any) -> None:
        raise NotImplementedError(
            "Playwright automation is intentionally not implemented in v0."
        )

    def placeholder_git_sync(self, *_args: Any, **_kwargs: Any) -> None:
        raise NotImplementedError(
            "Git automation is intentionally not implemented in v0."
        )

    def begin_cycle(
        self,
        *,
        task_id: str,
        human_goal: str,
        trigger_source: str = "human",
    ) -> CycleState:
        s = self._state.read()
        if s.state not in (CyclePhase.IDLE.value, CyclePhase.COMPLETED.value):
            raise CycleStateError(
                f"Cannot begin_cycle: current state is {s.state!r} "
                f"(expected {CyclePhase.IDLE.value!r} or {CyclePhase.COMPLETED.value!r})."
            )
        cid = str(uuid.uuid4())
        routing = load_json_file(self._paths.model_routing_config)
        route = resolve_model_route(routing, "orchestrator_intake")
        new = CycleState(
            cycle_id=cid,
            state=CyclePhase.PREPARING_INTAKE.value,
            task_id=task_id,
            validation_status=ValidationStatus.PENDING.value,
            validation_detail="",
            playwright_status=PlaywrightStatus.SKIPPED.value,
            playwright_detail="",
            block_reason=None,
            manual_gate_reason=None,
            active_model_route=route.to_json_dict(),
            claude_round="plan",
        )
        self._state.write(new)
        self._build_and_store_intake(
            cycle_id=cid,
            task_id=task_id,
            human_goal=human_goal,
            trigger_source=trigger_source,
            model_routing=routing,
        )
        after = self._state.read()
        self._require_state(after, CyclePhase.PREPARING_INTAKE)
        after.state = CyclePhase.WAITING_CLAUDE.value
        after.touch()
        self._state.write(after)
        return self._state.read()

    def _build_and_store_intake(
        self,
        *,
        cycle_id: str,
        task_id: str,
        human_goal: str,
        trigger_source: str,
        model_routing: Mapping[str, Any],
    ) -> None:
        packet = self._intake.build(
            cycle_id=cycle_id,
            task_id=task_id,
            human_goal=human_goal,
            trigger_source=trigger_source,
            model_routing=model_routing,
            task_kind="orchestrator_intake",
        )
        self._handoffs.save_claude_intake(cycle_id, packet)

    def register_claude_plan_response(self, packet: ClaudeResponsePacket) -> CycleState:
        s = self._state.read()
        self._require_state(s, CyclePhase.WAITING_CLAUDE)
        if s.claude_round != "plan":
            raise CycleStateError("Expected claude_round='plan' for plan ingestion.")
        if packet.cycle_id != s.cycle_id or (s.task_id and packet.task_id != s.task_id):
            raise CycleStateError("ClaudeResponsePacket cycle_id/task_id mismatch.")
        if packet.response_kind != "plan":
            raise CycleStateError("Expected response_kind='plan' for this transition.")
        self._handoffs.save_claude_response(s.cycle_id, packet)

        if (packet.human_decision or "").upper() == "STOP":
            return self._to_manual_gate(s, "human_decision=STOP on plan")
        if (packet.suggested_next_actor or "").lower() in (
            "human_gate",
            "manual_gate",
        ):
            return self._to_manual_gate(s, "suggested_next_actor requests human gate")

        if (packet.suggested_next_actor or "").lower() in (
            "cursor_execute",
            "cursor",
        ):
            cursor_pkt = self._build_cursor_packet_from_plan(s, packet)
            self._handoffs.save_cursor_execution(s.cycle_id, cursor_pkt)
            s.state = CyclePhase.WAITING_CURSOR.value
            s.touch()
            self._state.write(s)
            return s

        if (packet.suggested_next_actor or "").lower() == "playwright":
            s.state = CyclePhase.WAITING_PLAYWRIGHT.value
            s.playwright_status = PlaywrightStatus.WAITING.value
            s.touch()
            self._state.write(s)
            return s

        raise CycleStateError(
            f"Unsupported suggested_next_actor: {packet.suggested_next_actor!r}"
        )

    def acknowledge_cursor_execution_started(self) -> None:
        """Optional hook for future telemetry; no state change in v0."""

    def register_cursor_finished(self) -> CycleState:
        s = self._state.read()
        self._require_state(s, CyclePhase.WAITING_CURSOR)
        s.state = CyclePhase.WAITING_VALIDATION.value
        s.touch()
        self._state.write(s)
        return s

    def register_validation_result(
        self,
        status: ValidationStatus,
        detail: str = "",
    ) -> CycleState:
        s = self._state.read()
        self._require_state(s, CyclePhase.WAITING_VALIDATION)
        s.validation_status = status.value
        s.validation_detail = detail
        if status == ValidationStatus.FAILED:
            s.state = CyclePhase.BLOCKED.value
            s.block_reason = detail or "validation_failed"
            s.touch()
            self._state.write(s)
            return s
        if status == ValidationStatus.PENDING:
            raise CycleStateError("Cannot complete validation while status is still pending.")
        if status == ValidationStatus.SKIPPED:
            s.validation_status = ValidationStatus.SKIPPED.value
        elif status == ValidationStatus.PASSED:
            s.validation_status = ValidationStatus.PASSED.value
        else:
            raise CycleStateError(f"Unsupported validation status: {status!r}")
        s.state = CyclePhase.WAITING_CLAUDE.value
        s.claude_round = "review"
        s.touch()
        self._state.write(s)
        return s

    def register_claude_review_response(self, packet: ClaudeResponsePacket) -> CycleState:
        s = self._state.read()
        self._require_state(s, CyclePhase.WAITING_CLAUDE)
        if s.claude_round != "review":
            raise CycleStateError("Expected claude_round='review' for review ingestion.")
        if packet.response_kind != "review":
            raise CycleStateError("Expected response_kind='review'.")
        if packet.cycle_id != s.cycle_id:
            raise CycleStateError("cycle_id mismatch.")
        self._handoffs.save_claude_response(s.cycle_id, packet)

        verdict = (packet.verdict or "").upper()
        if verdict == "MANUAL_GATE":
            return self._to_manual_gate(
                s, packet.objective or "review requests manual gate"
            )
        if verdict == "NEEDS_ANTIGRAVITY":
            s.state = CyclePhase.WAITING_PLAYWRIGHT.value
            s.playwright_status = PlaywrightStatus.WAITING.value
            s.touch()
            self._state.write(s)
            return s
        if verdict == "NEEDS_FIX":
            s.state = CyclePhase.WAITING_CURSOR.value
            s.claude_round = "plan"
            s.touch()
            self._state.write(s)
            return s
        if verdict == "APPROVED":
            s.state = CyclePhase.COMPLETED.value
            s.touch()
            self._state.write(s)
            return s
        raise CycleStateError(f"Unknown verdict: {packet.verdict!r}")

    def register_playwright_result(
        self,
        status: PlaywrightStatus,
        detail: str = "",
    ) -> CycleState:
        s = self._state.read()
        self._require_state(s, CyclePhase.WAITING_PLAYWRIGHT)
        s.playwright_status = status.value
        s.playwright_detail = detail
        if status == PlaywrightStatus.FAILED:
            s.state = CyclePhase.BLOCKED.value
            s.block_reason = detail or "playwright_failed"
            s.touch()
            self._state.write(s)
            return s
        if status in (PlaywrightStatus.PASSED, PlaywrightStatus.SKIPPED):
            s.state = CyclePhase.WAITING_CLAUDE.value
            s.claude_round = "review"
            s.touch()
            self._state.write(s)
            return s
        raise CycleStateError(f"Unsupported playwright status: {status!r}")

    def force_blocked(self, reason: str) -> CycleState:
        s = self._state.read()
        s.state = CyclePhase.BLOCKED.value
        s.block_reason = reason
        s.touch()
        self._state.write(s)
        return s

    def force_manual_gate(self, reason: str) -> CycleState:
        s = self._state.read()
        return self._to_manual_gate(s, reason)

    def try_enter_manual_gate_for_claude_human_verification(self) -> tuple[bool, str]:
        """
        When Claude web shows an anti-bot / human verification page, move the cycle to manual_gate
        so operators use handoff + inbox instead of Playwright. No-op if FSM is not waiting_claude.
        """
        s = self._state.read()
        if s.state != CyclePhase.WAITING_CLAUDE.value:
            return (
                False,
                f"cycle state is {s.state!r} (expected {CyclePhase.WAITING_CLAUDE.value!r}); left unchanged",
            )
        self._to_manual_gate(
            s,
            "Claude web human verification / anti-bot page blocked browser automation. "
            "Manual handoff: paste the handoff from bot/outbox/claude/ into Claude in your browser; "
            "drop valid JSON into the supervisor inbox for this round; then run-supervisor-once. "
            "See bot/docs/CLAUDE_WEB_LIMITATION.md.",
        )
        return True, CyclePhase.MANUAL_GATE.value

    def reset_to_idle(self) -> CycleState:
        s = CycleState()
        self._state.write(s)
        return s

    def _require_state(self, s: CycleState, expected: CyclePhase) -> None:
        if s.state != expected.value:
            raise CycleStateError(
                f"Expected state {expected.value!r}, got {s.state!r}."
            )

    def _to_manual_gate(self, s: CycleState, reason: str) -> CycleState:
        s.state = CyclePhase.MANUAL_GATE.value
        s.manual_gate_reason = reason
        s.touch()
        self._state.write(s)
        return s

    def _build_cursor_packet_from_plan(
        self,
        s: CycleState,
        plan: ClaudeResponsePacket,
    ) -> CursorExecutionPacket:
        paths_cfg = merge_paths_config(load_json_file(self._paths.paths_config))
        planning_path = paths_cfg.get("planning_latest", "reports/planning/latest.md")
        files = list(plan.files_allowed)
        commands = self._default_validation_commands(plan.test_stance)
        return CursorExecutionPacket(
            cycle_id=s.cycle_id,
            task_id=s.task_id or "",
            planning_path=planning_path,
            files_allowed=files,
            validation_commands=commands,
        )

    @staticmethod
    def _default_validation_commands(test_stance: str) -> list[str]:
        ts = (test_stance or "").lower()
        if "no-test" in ts or "no_test" in ts:
            return []
        if "antigravity" in ts or "anti-gravity" in ts:
            return ["placeholder: playwright or CI job (not run in v0)"]
        return ["php artisan test --filter=Order"]
