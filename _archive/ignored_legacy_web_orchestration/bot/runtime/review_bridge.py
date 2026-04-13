"""
Deterministic review-phase handoff and cycle-folder listing (file-based only).

Used after validation moves the cycle to ``waiting_claude`` with ``claude_round=review``.
At that moment ``claude_response.json`` still holds the **plan** until the operator
registers the review JSON (which overwrites the same file).
"""

from __future__ import annotations

from pathlib import Path

from bot.runtime.handoff_manager import HandoffManager
from bot.runtime.init import (
    RuntimePaths,
    load_json_file,
    merge_paths_config,
    resolve_model_route,
)
from bot.runtime.models import ClaudeResponsePacket, CycleState
from bot.runtime.prompt_compiler import SNIPPET_CHAR_LIMIT, TRUNCATION_MARKER


class ReviewBridgeError(ValueError):
    """Invalid state or missing data for review handoff."""


class ReviewBridge:
    """Compile review Markdown and enumerate cycle artifacts (no side effects on state)."""

    REVIEW_HANDOFF_FILENAME = "claude_review_handoff.md"

    def __init__(self, paths: RuntimePaths, handoffs: HandoffManager) -> None:
        self._paths = paths
        self._handoffs = handoffs

    def write_review_handoff(self, state: CycleState) -> Path:
        if not state.cycle_id:
            raise ReviewBridgeError(
                "No active cycle_id. Run `begin-cycle` first (one cycle at a time)."
            )
        if state.state != "waiting_claude" or state.claude_round != "review":
            raise ReviewBridgeError(
                "build-review-handoff requires persisted state `waiting_claude` and "
                "`claude_round=review` (e.g. after `register-validation-result` passed|skipped). "
                f"Got state={state.state!r}, claude_round={state.claude_round!r}."
            )
        body = self._render_review_markdown(state)
        d = self._handoffs.handoff_dir(state.cycle_id)
        d.mkdir(parents=True, exist_ok=True)
        out = d / self.REVIEW_HANDOFF_FILENAME
        out.write_text(body, encoding="utf-8")
        return out

    def _render_review_markdown(self, state: CycleState) -> str:
        paths_cfg = merge_paths_config(load_json_file(self._paths.paths_config))
        routing_cfg = load_json_file(self._paths.model_routing_config)
        review_route = resolve_model_route(routing_cfg, "claude_review")

        response = self._handoffs.load_claude_response(state.cycle_id)
        plan_block = self._plan_context_block(response)

        exec_rel = paths_cfg.get("execution_latest")
        exec_snip = self._snippet_for_rel(exec_rel)

        rev_rel = paths_cfg.get("review_latest")
        rev_snip = self._snippet_for_rel(rev_rel)

        state_lines = [
            f"- **cycle_id**: `{state.cycle_id}`",
            f"- **persisted_state**: `{state.state}`",
            f"- **claude_round**: `{state.claude_round}`",
            f"- **task_id**: `{state.task_id or ''}`",
            f"- **validation_status**: `{state.validation_status}`",
            f"- **validation_detail**: `{state.validation_detail or ''}`",
            f"- **playwright_status**: `{state.playwright_status}`",
        ]

        route_lines = "\n".join(
            f"- **{k}**: `{v}`"
            for k, v in sorted(review_route.to_json_dict().items())
        )

        lines: list[str] = [
            "# FoodKing — Claude **review** handoff (bot v0)",
            "",
            "## Cycle state (from `bot/state/cycle_state.json`)",
            "\n".join(state_lines),
            "",
            "## Current phase",
            "- You are in the **review** round: produce a **review** JSON (`response_kind: review`, `verdict` set).",
            "- Register it with `register-review-response` (alias of `register-claude-review`) **after** saving the file.",
            "- **Before** registration, `claude_response.json` should still contain the accepted **plan**; this handoff embeds that plan context below when possible.",
            "",
            "## Model routing (from `bot/config/model_routing.json`, key `claude_review`)",
            route_lines,
            "",
            "## Latest Claude plan context (from `claude_response.json` while still a plan)",
            plan_block,
            "",
            "## Execution report (`reports/execution/latest.md`)",
            f"### Path → `{exec_rel}`" if isinstance(exec_rel, str) else "### Path → *(not configured)*",
            exec_snip if isinstance(exec_rel, str) else "```\n(missing path config)\n```",
            "",
            "## Prior review notes (`reports/review/latest.md`, if present)",
            f"### Path → `{rev_rel}`" if isinstance(rev_rel, str) else "### Path → *(not configured)*",
            rev_snip if isinstance(rev_rel, str) else "```\n(missing path config)\n```",
            "",
            "## Requested output type",
            "- Claude **review** JSON (see `bot/examples/review_response.example.json`).",
            "- Then: `python bot/cli.py register-review-response --file …` or `.\bot-cli.ps1 register-review-response --file …`",
            "",
            "## Verdict → next persisted state (deterministic)",
            "- `APPROVED` → `completed`",
            "- `NEEDS_FIX` → `waiting_cursor` with `claude_round` reset to `plan`",
            "- `NEEDS_ANTIGRAVITY` → `waiting_playwright`",
            "- `MANUAL_GATE` → `manual_gate`",
            "",
            "## Decision expectation",
            "- Base the verdict on execution evidence in `reports/execution/latest.md` and repo rules.",
            "- Do not invent test results; only reference what is recorded or explicitly supplied by the operator.",
        ]
        return "\n".join(lines) + "\n"

    def _plan_context_block(self, response: ClaudeResponsePacket | None) -> str:
        if not response:
            return "- *(no `claude_response.json` on disk)*"
        if response.response_kind != "plan":
            return (
                "- **`claude_response.json` is not a plan** (`response_kind` != `plan`). "
                "Plan context cannot be shown (review may already be registered, or file was replaced). "
                "Re-run `build-review-handoff` only while the plan file is still present, **before** `register-review-response`."
            )
        parts = [
            f"- **objective**: {response.objective}",
            f"- **risk_class**: `{response.risk_class}`",
            f"- **test_stance**: `{response.test_stance}`",
            f"- **suggested_next_actor (at plan time)**: `{response.suggested_next_actor}`",
            f"- **files_allowed**: {', '.join(f'`{p}`' for p in response.files_allowed) or '*(none)*'}",
            f"- **scope_non_goals**:",
        ]
        if response.scope_non_goals:
            for s in response.scope_non_goals:
                parts.append(f"  - {s}")
        else:
            parts.append("  - *(none)*")
        return "\n".join(parts)

    def _snippet_for_rel(self, rel: str | None) -> str:
        if not isinstance(rel, str) or not rel.strip():
            return "```\n(missing path config)\n```"
        p = self._paths.repo_root / rel
        return self._verbatim_snippet(p)

    def _verbatim_snippet(self, file_path: Path) -> str:
        if not file_path.is_file():
            return "```\n(missing file)\n```"
        raw = file_path.read_text(encoding="utf-8-sig", errors="replace")
        body = (
            raw
            if len(raw) <= SNIPPET_CHAR_LIMIT
            else raw[:SNIPPET_CHAR_LIMIT] + TRUNCATION_MARKER
        )
        return "```\n" + body + "\n```"

    @staticmethod
    def list_cycle_artifact_paths(state_dir: Path, cycle_id: str) -> list[Path]:
        """Sorted paths to existing files directly under the cycle handoff folder."""
        hm = HandoffManager(state_dir)
        d = hm.handoff_dir(cycle_id)
        if not d.is_dir():
            return []
        return sorted((p for p in d.iterdir() if p.is_file()), key=lambda p: p.name.lower())


def format_cycle_files_lines(state_dir: Path, cycle_id: str) -> list[str]:
    """One absolute path per line for operator scripts."""
    paths = ReviewBridge.list_cycle_artifact_paths(state_dir, cycle_id)
    return [str(p.resolve()) for p in paths]
