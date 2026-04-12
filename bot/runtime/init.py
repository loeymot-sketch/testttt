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


def default_paths_config() -> dict[str, str]:
    """Fallback when paths.json is missing (matches paths.example.json)."""
    return {
        "planning_latest": "reports/planning/latest.md",
        "execution_latest": "reports/execution/latest.md",
        "review_latest": "reports/review/latest.md",
        "antigravity_latest": "reports/antigravity/latest.md",
        "bugbot_latest": "reports/review/bugbot-latest.md",
    }


def merge_paths_config(on_disk: Mapping[str, Any]) -> dict[str, str]:
    out = default_paths_config()
    for k, v in on_disk.items():
        if k.startswith("_") or k == "version":
            continue
        if isinstance(v, str):
            out[str(k)] = v
    return out
