"""
Extract the first valid JSON object from mixed assistant text (stdlib json + scan).

Deterministic errors; validates plan/review/cursor_done/validation_result shapes using
shared heuristics from ``bot.browser_bridge.cursor_browser_bridge`` where applicable.
"""

from __future__ import annotations

import json
from typing import Any

from bot.browser_bridge.cursor_browser_bridge import (
    cursor_done_success_heuristic,
    validation_success_heuristic,
)


def _strip_outer_markdown_fence(text: str) -> tuple[str, str]:
    """
    If text is wrapped in ``` or ```json fences, return inner content.
    If fences exist but inner is not extractable, returns ("", error).
    """
    t = text.strip()
    if not t.startswith("```"):
        return text, ""
    # opening fence: ``` or ```json
    first_nl = t.find("\n")
    if first_nl == -1:
        return "", "fence_open_no_newline"
    inner = t[first_nl + 1 :]
    close = inner.rfind("```")
    if close == -1:
        return "", "fence_unclosed"
    inner = inner[:close].strip()
    return inner, ""


def extract_first_json_object(text: str) -> tuple[dict[str, Any] | None, str]:
    """
    Return (dict, "") on success, or (None, deterministic error code / message).

    Steps:
    1. Trim; if fenced, strip fences first.
    2. Scan for first '{' and parse balanced object with string/escape awareness.
    3. json.loads on candidate; on failure return (None, error).
    """
    if not text or not str(text).strip():
        return None, "empty_input"

    raw = text
    stripped, fence_err = _strip_outer_markdown_fence(text)
    if fence_err:
        return None, f"fence_error:{fence_err}"
    work = stripped if stripped else raw

    start = work.find("{")
    if start == -1:
        return None, "no_opening_brace"

    depth = 0
    in_string: str | None = None
    escape = False
    for j in range(start, len(work)):
        ch = work[j]
        if escape:
            escape = False
            continue
        if in_string:
            if ch == "\\":
                escape = True
            elif ch == in_string:
                in_string = None
            continue
        if ch == '"':
            in_string = '"'
            continue
        if ch == "'":
            in_string = "'"
            continue
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                candidate = work[start : j + 1]
                try:
                    obj = json.loads(candidate)
                except json.JSONDecodeError as e:
                    # Fenced markdown with invalid JSON inside -> reject
                    return None, f"json_decode_error:{e.msg}:line{e.lineno}:col{e.colno}"
                if not isinstance(obj, dict):
                    return None, "root_must_be_object"
                return obj, ""
    return None, "unbalanced_braces"


def validate_plan_shape(data: dict[str, Any]) -> tuple[bool, str]:
    if data.get("response_kind") != "plan":
        return False, "expected_response_kind_plan"
    if not str(data.get("cycle_id", "")).strip():
        return False, "missing_cycle_id"
    return True, ""


def validate_review_shape(data: dict[str, Any]) -> tuple[bool, str]:
    if data.get("response_kind") != "review":
        return False, "expected_response_kind_review"
    if not str(data.get("cycle_id", "")).strip():
        return False, "missing_cycle_id"
    v = str(data.get("verdict", "")).upper()
    allowed = {"APPROVED", "NEEDS_FIX", "NEEDS_PLAYWRIGHT", "BLOCKED", "MANUAL_GATE"}
    if v not in allowed:
        return False, f"invalid_verdict:{v!r}"
    return True, ""


def extract_plan_or_review(text: str, expected_kind: str) -> tuple[dict[str, Any] | None, str]:
    """
    expected_kind: 'plan' | 'review'
    """
    obj, err = extract_first_json_object(text)
    if obj is None:
        return None, err
    if expected_kind == "plan":
        ok, msg = validate_plan_shape(obj)
        return (obj, "") if ok else (None, msg)
    ok, msg = validate_review_shape(obj)
    return (obj, "") if ok else (None, msg)


def extract_cursor_done(text: str) -> tuple[dict[str, Any] | None, str]:
    obj, err = extract_first_json_object(text)
    if obj is None:
        return None, err
    ok, msg = cursor_done_success_heuristic(obj)
    return (obj, "") if ok else (None, msg)


def extract_validation_result(text: str) -> tuple[dict[str, Any] | None, str]:
    obj, err = extract_first_json_object(text)
    if obj is None:
        return None, err
    ok, msg = validation_success_heuristic(obj)
    return (obj, "") if ok else (None, msg)
