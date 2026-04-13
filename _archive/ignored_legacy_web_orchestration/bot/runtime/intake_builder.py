"""Build ClaudeIntakePacket from repo files (paths config only; bounded reads)."""

from __future__ import annotations

from pathlib import Path
from typing import Any, Mapping

from bot.runtime.init import RuntimePaths, load_json_file, merge_paths_config, resolve_model_route
from bot.runtime.models import ClaudeIntakePacket, IntakeSection


class IntakeBuilder:
    def __init__(
        self,
        paths: RuntimePaths,
        *,
        max_section_chars: int = 12000,
    ) -> None:
        self._paths = paths
        self._max_section_chars = max_section_chars

    def build(
        self,
        *,
        cycle_id: str,
        task_id: str,
        human_goal: str,
        trigger_source: str,
        model_routing: Mapping[str, Any],
        task_kind: str = "orchestrator_intake",
    ) -> ClaudeIntakePacket:
        paths_cfg = merge_paths_config(load_json_file(self._paths.paths_config))
        route = resolve_model_route(dict(model_routing), task_kind)
        sections: list[IntakeSection] = []

        core_keys = (
            "planning_latest",
            "execution_latest",
            "review_latest",
            "antigravity_latest",
            "bugbot_latest",
        )
        for key in core_keys:
            rel = str(paths_cfg.get(key, ""))
            if not rel:
                continue
            body, truncated = self._read_bounded(self._paths.repo_root / rel)
            sections.append(
                IntakeSection(
                    title=key,
                    source_path=rel,
                    body=body,
                    truncated=truncated,
                )
            )

        for key in ("claude_md", "memory_md"):
            rel = paths_cfg.get(key)
            if not isinstance(rel, str) or not rel.strip():
                continue
            body, truncated = self._read_bounded(self._paths.repo_root / rel)
            sections.append(
                IntakeSection(
                    title=key,
                    source_path=rel,
                    body=body,
                    truncated=truncated,
                )
            )

        for entry in paths_cfg.get("intake_extra_sections") or []:
            if not isinstance(entry, Mapping):
                continue
            rel = entry.get("path")
            if not isinstance(rel, str) or not rel.strip():
                continue
            title = str(entry.get("title") or rel)
            body, truncated = self._read_bounded(self._paths.repo_root / rel)
            sections.append(
                IntakeSection(
                    title=title,
                    source_path=rel,
                    body=body,
                    truncated=truncated,
                )
            )

        return ClaudeIntakePacket(
            cycle_id=cycle_id,
            task_id=task_id,
            trigger_source=trigger_source,
            human_goal=human_goal,
            model_route=route.to_json_dict(),
            sections=sections,
        )

    def _read_bounded(self, file_path: Path) -> tuple[str, bool]:
        if not file_path.is_file():
            return "", False
        raw = file_path.read_text(encoding="utf-8", errors="replace")
        if len(raw) <= self._max_section_chars:
            return raw, False
        return raw[: self._max_section_chars], True
