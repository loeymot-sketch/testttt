"""Atomic read/write for cycle_state.json (one cycle at a time)."""

from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from typing import Any, Mapping

from bot.runtime.models import CycleState


class StateManager:
    def __init__(self, state_file: Path) -> None:
        self._path = state_file.resolve()
        self._path.parent.mkdir(parents=True, exist_ok=True)

    @property
    def path(self) -> Path:
        return self._path

    def read(self) -> CycleState:
        if not self._path.is_file():
            return CycleState()
        raw = self._path.read_text(encoding="utf-8")
        data: Mapping[str, Any] = json.loads(raw)
        return CycleState.from_json_dict(data)

    def write(self, state: CycleState) -> None:
        state.touch()
        payload = json.dumps(
            state.to_json_dict(),
            indent=2,
            ensure_ascii=False,
            sort_keys=True,
        )
        self._atomic_write_text(self._path, payload)

    @staticmethod
    def _atomic_write_text(dest: Path, text: str) -> None:
        dest.parent.mkdir(parents=True, exist_ok=True)
        fd, tmp = tempfile.mkstemp(
            prefix="cycle_state_",
            suffix=".json.tmp",
            dir=str(dest.parent),
        )
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                fh.write(text)
                fh.flush()
                os.fsync(fh.fileno())
            os.replace(tmp, dest)
        except Exception:
            try:
                os.unlink(tmp)
            except OSError:
                pass
            raise
