"""Bootstrap helpers, version, and model-route resolution (config only; no API calls)."""

from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Mapping

from bot.runtime.models import ModelRoute

VERSION = "0.1.0"


@dataclass(frozen=True)
class RuntimePaths:
    """Resolved layout under a FoodKing repo root."""

    repo_root: Path
    bot_dir: Path
    state_dir: Path
    logs_dir: Path
    config_dir: Path
    cycle_state_file: Path
    paths_config: Path
    model_routing_config: Path

    @classmethod
    def from_repo_root(cls, repo_root: Path) -> RuntimePaths:
        root = repo_root.resolve()
        bot = root / "bot"
        state = bot / "state"
        return cls(
            repo_root=root,
            bot_dir=bot,
            state_dir=state,
            logs_dir=bot / "logs",
            config_dir=bot / "config",
            cycle_state_file=state / "cycle_state.json",
            paths_config=bot / "config" / "paths.json",
            model_routing_config=bot / "config" / "model_routing.json",
        )

    @classmethod
    def from_bot_config(cls, repo_root: Path, cfg: Mapping[str, Any]) -> RuntimePaths:
        """Paths relative to repo_root unless an entry is an absolute path."""
        root = repo_root.resolve()
        bot = root / "bot"

        def _p(rel_or_abs: str, default: str) -> Path:
            raw = str(cfg.get(rel_or_abs, default) or default)
            p = Path(raw)
            return p if p.is_absolute() else (root / p)

        state_dir = _p("state_dir", "bot/state")
        logs_dir = _p("logs_dir", "bot/logs")
        return cls(
            repo_root=root,
            bot_dir=bot,
            state_dir=state_dir,
            logs_dir=logs_dir,
            config_dir=bot / "config",
            cycle_state_file=_p("cycle_state_path", "bot/state/cycle_state.json"),
            paths_config=_p("paths_config_path", "bot/config/paths.json"),
            model_routing_config=_p(
                "model_routing_path", "bot/config/model_routing.json"
            ),
        )


def infer_repo_root_from_bot_config_file(config_path: Path) -> Path:
    """
    For a file at ``<repo>/bot/config/bot_config.json``, return ``<repo>``.
    """
    p = config_path.resolve()
    return p.parent.parent.parent


def resolve_repo_root(
    cfg: Mapping[str, Any],
    bot_config_file: Path,
) -> Path:
    rr = cfg.get("repo_root")
    if isinstance(rr, str) and rr.strip():
        return Path(rr).expanduser().resolve()
    return infer_repo_root_from_bot_config_file(bot_config_file)


def load_json_file(path: Path) -> dict[str, Any]:
    if not path.is_file():
        return {}
    raw = path.read_text(encoding="utf-8")
    data = json.loads(raw)
    return data if isinstance(data, dict) else {}


def resolve_model_route(
    routing_config: Mapping[str, Any],
    task_kind: str,
) -> ModelRoute:
    """Pick route from model_routing.json structure (example + real)."""
    default = routing_config.get("default") or {}
    routes = routing_config.get("routes") or {}
    entry = routes.get(task_kind)
    if isinstance(entry, Mapping):
        merged: dict[str, Any] = {**dict(default), **dict(entry)}
    else:
        merged = dict(default)
    return ModelRoute.from_mapping(merged)


def default_paths_document() -> dict[str, Any]:
    """
    Default path map + optional extra intake sections (FoodKing-oriented).
    ``merge_paths_config`` returns this shape merged with ``paths.json``.
    """
    return {
        "planning_latest": "reports/planning/latest.md",
        "execution_latest": "reports/execution/latest.md",
        "review_latest": "reports/review/latest.md",
        "antigravity_latest": "reports/antigravity/latest.md",
        "bugbot_latest": "reports/review/bugbot-latest.md",
        "claude_md": "CLAUDE.md",
        "memory_md": "MEMORY.md",
        "intake_extra_sections": _default_intake_extra_sections(),
    }


def _default_intake_extra_sections() -> list[dict[str, str]]:
    """Stable subset of docs/ops and docs/roles for orchestration context."""
    ops = [
        "docs/ops/CLAUDE_CYCLE_INTAKE.md",
        "docs/ops/BOT_TO_CLAUDE_RUNTIME_CONTRACT.md",
        "docs/ops/CLAUDE_CYCLE_OUTPUT.md",
        "docs/ops/CLAUDE_SCORING_RUBRIC.md",
        "docs/ops/CURSOR_MODEL_ROUTING_POLICY.md",
        "docs/ops/CYCLE_002b_LOCAL_VALIDATION_COMMAND_PACK.md",
    ]
    roles = [
        "docs/roles/00_ORCHESTRATOR_ROLE.md",
        "docs/roles/01_ARCHITECTURE_MEMORY_ROLE.md",
        "docs/roles/02_PRODUCT_VISION_UX_ROLE.md",
        "docs/roles/03_DEEP_AUDIT_ROLE.md",
        "docs/roles/04_RESEARCH_BENCHMARK_ROLE.md",
    ]
    out: list[dict[str, str]] = []
    for rel in ops:
        out.append({"title": rel.replace("/", "__"), "path": rel})
    for rel in roles:
        out.append({"title": rel.replace("/", "__"), "path": rel})
    return out


def merge_paths_config(on_disk: Mapping[str, Any]) -> dict[str, Any]:
    out = default_paths_document()
    for k, v in on_disk.items():
        if k.startswith("_") or k == "version":
            continue
        if k == "intake_extra_sections" and isinstance(v, list):
            cleaned: list[dict[str, str]] = []
            for item in v:
                if isinstance(item, dict) and "path" in item:
                    title = str(item.get("title") or item["path"])
                    cleaned.append({"title": title, "path": str(item["path"])})
            out["intake_extra_sections"] = cleaned
        elif isinstance(v, str):
            out[str(k)] = v
    return out
