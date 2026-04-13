"""
Deterministic Markdown handoffs for human paste into Claude Project / Cursor.

No summarization, no LLM calls: fixed templates + bounded verbatim excerpts only.
"""

from __future__ import annotations

from pathlib import Path
from typing import Any

from bot.runtime.handoff_manager import HandoffManager
from bot.runtime.init import (
    RuntimePaths,
    load_json_file,
    merge_paths_config,
    resolve_model_route,
)
from bot.runtime.models import (
    ClaudeIntakePacket,
    ClaudeResponsePacket,
    CursorExecutionPacket,
    CycleState,
)

# Verbatim excerpt cap (characters). If exceeded, a fixed marker is appended.
SNIPPET_CHAR_LIMIT = 3500
TRUNCATION_MARKER = "\n\n<!-- bot v0: truncated at SNIPPET_CHAR_LIMIT chars -->\n"

# CLI hint (Windows-friendly: repo-root `bot-cli.ps1` sets PYTHONPATH).
_CLI_CMD = "`.\\bot-cli.ps1` (Windows, repo root) or `python bot/cli.py` with `PYTHONPATH=.`"


class PromptCompilerError(ValueError):
    """Missing handoff data or invalid cycle for compilation."""


class PromptCompiler:
    CLAUDE_HANDOFF_FILENAME = "claude_handoff.md"
    CURSOR_HANDOFF_FILENAME = "cursor_handoff.md"

    def __init__(self, paths: RuntimePaths, handoffs: HandoffManager) -> None:
        self._paths = paths
        self._handoffs = handoffs

    def write_claude_handoff(self, state: CycleState) -> Path:
        if not state.cycle_id:
            raise PromptCompilerError(
                "No cycle_id on disk (idle). Run `begin-cycle --task-id … --goal …` first."
            )
        text = self.render_claude_markdown(state)
        return self._write_md(state.cycle_id, self.CLAUDE_HANDOFF_FILENAME, text)

    def write_cursor_handoff(self, state: CycleState) -> Path:
        if not state.cycle_id:
            raise PromptCompilerError("No cycle_id on disk (idle).")
        cursor = self._handoffs.load_cursor_execution(state.cycle_id)
        if cursor is None:
            raise PromptCompilerError(
                "Missing cursor_execution.json for this cycle. "
                "Register a Claude plan with suggested_next_actor=cursor_execute first "
                "(`register-claude-response`)."
            )
        text = self.render_cursor_markdown(state, cursor)
        return self._write_md(state.cycle_id, self.CURSOR_HANDOFF_FILENAME, text)

    def _write_md(self, cycle_id: str, filename: str, body: str) -> Path:
        d = self._handoffs.handoff_dir(cycle_id)
        d.mkdir(parents=True, exist_ok=True)
        p = d / filename
        p.write_text(body, encoding="utf-8")
        return p

    def render_claude_markdown(self, state: CycleState) -> str:
        intake = self._handoffs.load_claude_intake(state.cycle_id)
        response = self._handoffs.load_claude_response(state.cycle_id)
        cursor = self._handoffs.load_cursor_execution(state.cycle_id)
        paths_cfg = merge_paths_config(load_json_file(self._paths.paths_config))

        objective = self._objective_line(intake, response, state)
        risk = self._risk_line(response)
        surfaces = self._surfaces_union(response, cursor)
        doc_table = self._intake_document_table(intake)
        excerpts = self._report_excerpts_block(paths_cfg)
        packet_meta = self._claude_packet_meta_block(response)

        out_type = (
            f"Claude **plan** JSON → save then run register-claude-response with {_CLI_CMD}: "
            f"`register-claude-response --file …`."
            if state.claude_round == "plan"
            else f"Claude **review** JSON (`response_kind: review`, `verdict` set) → "
            f"`register-claude-review --file …` with {_CLI_CMD}."
        )
        phase_block = self._current_phase_block(state)

        lines = [
            "# FoodKing — Claude handoff (bot v0)",
            "",
            "## Cycle metadata",
            f"- **cycle_id**: `{state.cycle_id}`",
            f"- **persisted_state**: `{state.state}`",
            f"- **claude_round**: `{state.claude_round}`",
            f"- **task_id**: `{state.task_id or ''}`",
            "",
            "## Current phase",
            phase_block,
            "",
            "## Objective",
            objective,
            "",
            "## Critical zones (risk class)",
            risk,
            "",
            "## Surfaces touched (paths / files)",
            surfaces,
            "",
            "## Registered Claude packet (from JSON, if any)",
            packet_meta,
            "",
            "## Repository documents tracked in intake JSON",
            "Full captured bodies (bounded per section at intake build time) live in:",
            f"- `bot/state/handoffs/{state.cycle_id}/claude_intake.json`",
            "",
            "### Section index (paths only)",
            doc_table,
            "",
            "## Report excerpts (verbatim, deterministic trim)",
            excerpts,
            "",
            "## Requested output type",
            out_type,
            "",
            "## Decision expectation",
            "- Obey FoodKing invariants: server-side pricing, authz, order lifecycle (see `CLAUDE.md` / `docs/`).",
            "- If scope is unsafe or ambiguous, respond with `human_decision: STOP` or `suggested_next_actor: human_gate` in the plan JSON.",
            "- Output must be **actionable** for the next bot step (plan JSON or review JSON), not prose-only unless paired with structured fields.",
        ]
        return "\n".join(lines) + "\n"

    def render_cursor_markdown(
        self, state: CycleState, cursor: CursorExecutionPacket
    ) -> str:
        response = self._handoffs.load_claude_response(state.cycle_id)
        non_goals = self._non_goals_block(response)
        routing_cfg = load_json_file(self._paths.model_routing_config)
        cursor_route = resolve_model_route(routing_cfg, "cursor_execution")
        routing_from_cfg = "\n".join(
            f"- **{k}**: `{v}`"
            for k, v in sorted(cursor_route.to_json_dict().items())
        )
        state_route = state.active_model_route or {}
        routing_snapshot = "\n".join(
            f"- **{k}**: `{v}`" for k, v in sorted(state_route.items())
        ) or "- *(none on cycle state — e.g. idle)*"

        files = "\n".join(f"- `{p}`" for p in cursor.files_allowed) or "- *(none listed)*"
        cmds = "\n".join(f"- `{c}`" for c in cursor.validation_commands) or "- *(none)*"
        test_stance = (
            f"`{response.test_stance}`"
            if response and response.test_stance.strip()
            else "`Kimi-test` (default if plan JSON not loaded)"
        )

        return "\n".join(
            [
                "# FoodKing — Cursor execution handoff (bot v0)",
                "",
                "## Cycle metadata",
                f"- **cycle_id**: `{state.cycle_id}`",
                f"- **persisted_state**: `{state.state}`",
                f"- **task_id**: `{state.task_id or ''}`",
                "",
                "## Execution scope",
                f"- **Planning file (human-readable)**: `{cursor.planning_path}`",
                f"- **Prepared at (packet)**: `{cursor.prepared_at}`",
                "",
                "## Allowed files (from plan → cursor_execution.json)",
                files,
                "",
                "## Requested task type",
                "- Implement / fix per **planning** above and repository rules.",
                "- After edits, run validation commands locally (see below).",
                "",
                "## Test strategy (from Claude plan JSON, if present)",
                test_stance,
                "",
                "## Model routing recommendation (from `bot/config/model_routing.json`)",
                "Resolved deterministically with task key **`cursor_execution`** (same key as cycle controller when preparing execution):",
                routing_from_cfg,
                "",
                "## Model route snapshot on cycle state (`active_model_route`)",
                "Value persisted in `bot/state/cycle_state.json` at last transition (often **orchestrator_intake** from `begin-cycle`, not Cursor):",
                routing_snapshot,
                "",
                "## Expected output artifacts",
                "- Update `reports/execution/latest.md` with commands run + results (per `AGENTS.md`).",
                "- Keep diffs scoped to **allowed files** unless human expands scope.",
                "",
                "## Validation commands (from packet; not executed by bot)",
                cmds,
                "",
                "## Stop conditions",
                "- Stop if a change would violate pricing, authz, or order-state rules.",
                "- Stop if required context is missing; leave a note in execution report.",
                "",
                "## Non-goals (from Claude plan JSON if present)",
                non_goals,
            ]
        ) + "\n"

    @staticmethod
    def _current_phase_block(state: CycleState) -> str:
        """Human-readable phase: persisted FSM state + Claude plan vs review round."""
        st = state.state or ""
        rnd = (state.claude_round or "plan").strip()
        if st == "waiting_claude" and rnd == "plan":
            meaning = (
                "Waiting for **Claude plan** output. Next bot step: "
                "`register-claude-response --file <plan.json>`."
            )
        elif st == "waiting_claude" and rnd == "review":
            meaning = (
                "Waiting for **Claude review** output. Next bot step: "
                "`register-claude-review --file <review.json>`."
            )
        else:
            meaning = (
                "Cycle is not in the “paste Claude JSON” step for this round; "
                "see `persisted_state` for the active orchestration phase "
                "(e.g. `waiting_cursor`, `waiting_validation`)."
            )
        return "\n".join(
            [
                f"- **Orchestration state**: `{st}`",
                f"- **Claude round**: `{rnd}`",
                f"- **Meaning**: {meaning}",
            ]
        )

    @staticmethod
    def _objective_line(
        intake: ClaudeIntakePacket | None,
        response: ClaudeResponsePacket | None,
        state: CycleState,
    ) -> str:
        if response and response.objective.strip():
            return response.objective.strip()
        if intake and intake.human_goal.strip():
            return intake.human_goal.strip()
        return "*(none — see intake JSON once begin-cycle has run)*"

    @staticmethod
    def _risk_line(response: ClaudeResponsePacket | None) -> str:
        if response and response.risk_class.strip():
            return f"`{response.risk_class.strip()}`"
        return "`unknown` until a Claude plan JSON is registered."

    @staticmethod
    def _surfaces_union(
        response: ClaudeResponsePacket | None,
        cursor: CursorExecutionPacket | None,
    ) -> str:
        paths: list[str] = []
        if response and response.files_allowed:
            paths.extend(response.files_allowed)
        if cursor and cursor.files_allowed:
            paths.extend(cursor.files_allowed)
        uniq = sorted({str(p) for p in paths})
        if not uniq:
            return "- *(none listed yet)*"
        return "\n".join(f"- `{p}`" for p in uniq)

    @staticmethod
    def _non_goals_block(response: ClaudeResponsePacket | None) -> str:
        if not response or not response.scope_non_goals:
            return "- *(none listed in plan JSON)*"
        return "\n".join(f"- {s}" for s in response.scope_non_goals)

    @staticmethod
    def _intake_document_table(intake: ClaudeIntakePacket | None) -> str:
        if not intake or not intake.sections:
            return "| section | source_path | truncated |\n|---|---|---|\n| *(no intake sections)* | | |"
        rows = ["| section | source_path | truncated |", "|---|---|---|"]
        for sec in intake.sections:
            rows.append(
                f"| `{sec.title}` | `{sec.source_path}` | `{sec.truncated}` |"
            )
        return "\n".join(rows)

    def _report_excerpts_block(self, paths_cfg: dict[str, Any]) -> str:
        parts: list[str] = []
        for label in (
            "planning_latest",
            "execution_latest",
            "review_latest",
        ):
            rel = paths_cfg.get(label)
            if not isinstance(rel, str) or not rel.strip():
                continue
            p = self._paths.repo_root / rel
            parts.append(f"### `{label}` → `{rel}`")
            parts.append(self._verbatim_snippet(p))
            parts.append("")
        if len(parts) == 0:
            return "*(no report paths configured)*"
        return "\n".join(parts).rstrip() + "\n"

    def _verbatim_snippet(self, file_path: Path) -> str:
        if not file_path.is_file():
            return "```\n(missing file)\n```"
        raw = file_path.read_text(encoding="utf-8", errors="replace")
        body = raw if len(raw) <= SNIPPET_CHAR_LIMIT else raw[:SNIPPET_CHAR_LIMIT] + TRUNCATION_MARKER
        # fenced block keeps deterministic boundary
        return "```\n" + body + "\n```"

    @staticmethod
    def _claude_packet_meta_block(response: ClaudeResponsePacket | None) -> str:
        if not response:
            return "- *(no `claude_response.json` yet — run `register-claude-response` or `register-claude-review` after pasting JSON)*"
        lines = [
            f"- **response_kind**: `{response.response_kind}`",
            f"- **test_stance**: `{response.test_stance}`",
            f"- **suggested_next_actor**: `{response.suggested_next_actor}`",
        ]
        if response.human_decision is not None:
            lines.append(f"- **human_decision**: `{response.human_decision}`")
        if response.verdict is not None:
            lines.append(f"- **verdict**: `{response.verdict}`")
        return "\n".join(lines)
