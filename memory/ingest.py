#!/usr/bin/env python3
"""
FoodKing — Graphiti memory ingester.

Reads JSONL episode files under memory/episodes/ and pushes each line to the
Graphiti MCP server (stdio JSON-RPC 2.0) via the `add_memory` tool.

Each JSONL line must be an object with at minimum:
    {"name": str, "episode_body": str, "source": "text"|"json"|"message"}
Optional: source_description (str), group_id (str), uuid (str).

Default group_id falls back to env GRAPHITI_GROUP_ID then literal "foodking".

Usage:
    python3 memory/ingest.py                          # ingest all files
    python3 memory/ingest.py 03_domain 11_production  # filter by substring
    DRY_RUN=1 python3 memory/ingest.py                # print only

The script spawns the same start script Cursor uses
(.cursor/mcp/start-graphiti-mcp.sh) so LiteLLM is auto-bootstrapped if needed.
"""
from __future__ import annotations

import asyncio
import hashlib
import json
import os
import sys
import uuid as _uuid
from pathlib import Path
from typing import Any

# Force unbuffered stdout/stderr so progress lines flush immediately under nohup/tail.
sys.stdout.reconfigure(line_buffering=True)
sys.stderr.reconfigure(line_buffering=True)

REPO_ROOT = Path(__file__).resolve().parent.parent
EPISODES_DIR = REPO_ROOT / "memory" / "episodes"
START_SCRIPT = REPO_ROOT / ".cursor" / "mcp" / "start-graphiti-mcp.sh"
DEFAULT_GROUP_ID = os.environ.get("GRAPHITI_GROUP_ID", "foodking")
DRY_RUN = os.environ.get("DRY_RUN", "").lower() in {"1", "true", "yes"}
# Per-call delay to stay polite with the queue and embedding worker
PER_CALL_DELAY_SEC = float(os.environ.get("INGEST_DELAY", "0.05"))
# stdio framing read timeout per response
RESPONSE_TIMEOUT_SEC = float(os.environ.get("INGEST_RESPONSE_TIMEOUT", "60"))


class MCPClient:
    """Minimal MCP stdio client (JSON-RPC 2.0 over newline-delimited JSON)."""

    def __init__(self, cmd: list[str]) -> None:
        self.cmd = cmd
        self.proc: asyncio.subprocess.Process | None = None
        self._next_id = 1
        self._lock = asyncio.Lock()

    async def start(self) -> None:
        self.proc = await asyncio.create_subprocess_exec(
            *self.cmd,
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
            limit=8 * 1024 * 1024,  # 8 MiB lines for large get_episodes responses
        )
        # Drain stderr in background so we can see startup logs without blocking
        asyncio.create_task(self._drain_stderr())
        await self._initialize()

    async def _drain_stderr(self) -> None:
        assert self.proc and self.proc.stderr
        while True:
            line = await self.proc.stderr.readline()
            if not line:
                return
            sys.stderr.write(f"[graphiti-mcp] {line.decode(errors='replace').rstrip()}\n")
            sys.stderr.flush()

    async def _send(self, payload: dict[str, Any]) -> dict[str, Any]:
        assert self.proc and self.proc.stdin and self.proc.stdout
        async with self._lock:
            data = (json.dumps(payload, ensure_ascii=False) + "\n").encode("utf-8")
            self.proc.stdin.write(data)
            await self.proc.stdin.drain()
            try:
                line = await asyncio.wait_for(
                    self.proc.stdout.readline(), timeout=RESPONSE_TIMEOUT_SEC
                )
            except asyncio.TimeoutError as exc:
                raise RuntimeError(
                    f"Graphiti MCP timeout after {RESPONSE_TIMEOUT_SEC}s on {payload.get('method')}"
                ) from exc
            if not line:
                raise RuntimeError("Graphiti MCP closed stdout unexpectedly")
            try:
                return json.loads(line.decode("utf-8"))
            except json.JSONDecodeError as exc:
                raise RuntimeError(f"Graphiti MCP returned non-JSON line: {line!r}") from exc

    async def _initialize(self) -> None:
        rid = self._alloc_id()
        resp = await self._send(
            {
                "jsonrpc": "2.0",
                "id": rid,
                "method": "initialize",
                "params": {
                    "protocolVersion": "2024-11-05",
                    "capabilities": {"tools": {}},
                    "clientInfo": {"name": "foodking-memory-ingester", "version": "1.0.0"},
                },
            }
        )
        if "error" in resp:
            raise RuntimeError(f"initialize failed: {resp['error']}")
        # Notify initialized
        notif = (
            json.dumps({"jsonrpc": "2.0", "method": "notifications/initialized"}) + "\n"
        ).encode("utf-8")
        assert self.proc and self.proc.stdin
        self.proc.stdin.write(notif)
        await self.proc.stdin.drain()

    def _alloc_id(self) -> int:
        self._next_id += 1
        return self._next_id

    async def call_tool(self, name: str, arguments: dict[str, Any]) -> dict[str, Any]:
        rid = self._alloc_id()
        resp = await self._send(
            {
                "jsonrpc": "2.0",
                "id": rid,
                "method": "tools/call",
                "params": {"name": name, "arguments": arguments},
            }
        )
        if "error" in resp:
            raise RuntimeError(f"tools/call {name} failed: {resp['error']}")
        return resp.get("result", {})

    async def close(self) -> None:
        if self.proc and self.proc.returncode is None:
            try:
                self.proc.stdin.close()
            except Exception:
                pass
            try:
                await asyncio.wait_for(self.proc.wait(), timeout=5)
            except asyncio.TimeoutError:
                self.proc.kill()


def discover_episode_files(filters: list[str]) -> list[Path]:
    if not EPISODES_DIR.exists():
        raise SystemExit(f"Episodes dir missing: {EPISODES_DIR}")
    files = sorted(p for p in EPISODES_DIR.glob("*.jsonl") if p.is_file())
    if not filters:
        return files
    matched: list[Path] = []
    for f in files:
        if any(flt in f.name for flt in filters):
            matched.append(f)
    return matched


def parse_episodes(path: Path) -> list[dict[str, Any]]:
    episodes: list[dict[str, Any]] = []
    for line_no, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        try:
            obj = json.loads(line)
        except json.JSONDecodeError as exc:
            raise SystemExit(f"{path}:{line_no} invalid JSON: {exc}")
        if "name" not in obj or "episode_body" not in obj:
            raise SystemExit(
                f"{path}:{line_no} missing required field 'name' or 'episode_body'"
            )
        obj.setdefault("source", "text")
        obj.setdefault("source_description", path.name)
        obj.setdefault("group_id", DEFAULT_GROUP_ID)
        episodes.append(obj)
    return episodes


async def main() -> int:
    filters = sys.argv[1:]
    files = discover_episode_files(filters)
    if not files:
        print("No episode files matched.", file=sys.stderr)
        return 1
    total_eps = 0
    print(f"[ingest] {len(files)} file(s) selected, group_id={DEFAULT_GROUP_ID}")
    for f in files:
        eps = parse_episodes(f)
        total_eps += len(eps)
        print(f"  - {f.name}: {len(eps)} episodes")
    print(f"[ingest] TOTAL = {total_eps} episodes  (DRY_RUN={DRY_RUN})")
    if DRY_RUN:
        return 0

    if not START_SCRIPT.exists():
        raise SystemExit(f"Start script missing: {START_SCRIPT}")

    client = MCPClient(["bash", str(START_SCRIPT)])
    print("[ingest] starting Graphiti MCP server (may take 30-90s on cold start)...")
    await client.start()
    print("[ingest] MCP server ready. Pushing episodes...")

    sent = 0
    failed: list[tuple[str, str]] = []
    try:
        for f in files:
            eps = parse_episodes(f)
            for ep in eps:
                args = {
                    "name": ep["name"],
                    "episode_body": ep["episode_body"]
                    if isinstance(ep["episode_body"], str)
                    else json.dumps(ep["episode_body"], ensure_ascii=False),
                    "source": ep.get("source", "text"),
                    "source_description": ep.get("source_description", f.name),
                    "group_id": ep.get("group_id", DEFAULT_GROUP_ID),
                }
                # NOTE: Do NOT pass `uuid` for new episodes. Graphiti's add_memory
                # treats `uuid` as a lookup key for an existing node (used for
                # updates), and returns "node not found" when the uuid does not
                # already exist. Idempotency is therefore left to the caller
                # (e.g. clear the group_id in Neo4j before a full re-run).
                if "uuid" in ep and ep["uuid"]:
                    args["uuid"] = ep["uuid"]
                try:
                    result = await client.call_tool("add_memory", args)
                    sent += 1
                    if sent % 5 == 0 or sent == total_eps:
                        print(f"  [{sent}/{total_eps}] {ep['name'][:80]}")
                except Exception as exc:
                    failed.append((ep["name"], str(exc)))
                    print(f"  [FAIL] {ep['name']}: {exc}", file=sys.stderr)
                await asyncio.sleep(PER_CALL_DELAY_SEC)

        # Drain the server queue: poll get_episodes(last_n=1) until count equals what we sent
        # (or until SKIP_DRAIN=1 / DRAIN_TIMEOUT seconds elapse).
        if os.environ.get("SKIP_DRAIN", "").lower() not in {"1", "true", "yes"}:
            drain_deadline = asyncio.get_event_loop().time() + float(
                os.environ.get("DRAIN_TIMEOUT", "1800")  # 30 min default
            )
            target_group = DEFAULT_GROUP_ID
            print(
                f"\n[ingest] draining server queue (group={target_group}, "
                "polling get_episodes every 15s)..."
            )
            last_count = -1
            stable_iters = 0
            while asyncio.get_event_loop().time() < drain_deadline:
                await asyncio.sleep(15)
                try:
                    res = await client.call_tool(
                        "get_episodes",
                        {"group_ids": [target_group], "max_episodes": 500},
                    )
                    text_blob = json.dumps(res)
                    cnt = text_blob.count('"uuid"')
                    print(f"  [drain] episodes visible in Neo4j: {cnt} / sent={sent}")
                    if cnt >= sent:
                        print("  [drain] target reached, queue drained.")
                        break
                    if cnt == last_count:
                        stable_iters += 1
                        # 40 * 15s = 10 min without any new completed episode → stop.
                        # Episodes can sit in queue while LLM extraction runs (~30-60s each)
                        # and concurrent workers may all be busy on heavier episodes.
                        no_progress_iters = int(
                            os.environ.get("DRAIN_STALL_ITERS", "40")
                        )
                        if stable_iters >= no_progress_iters:
                            print(
                                f"  [drain] no progress for {no_progress_iters * 15}s "
                                f"(count={cnt}), stopping drain wait."
                            )
                            break
                    else:
                        stable_iters = 0
                        last_count = cnt
                except Exception as exc:
                    print(f"  [drain] get_episodes error: {exc}", file=sys.stderr)
                    break
            else:
                print(
                    f"  [drain] DRAIN_TIMEOUT reached without full drain (last={last_count})"
                )
    finally:
        await client.close()

    print("\n[ingest] DONE")
    print(f"  sent: {sent}/{total_eps}")
    print(f"  failed: {len(failed)}")
    for name, err in failed:
        print(f"    - {name}: {err}")
    print(
        "\nNote: Graphiti queues episodes asynchronously; entity/edge extraction"
        " happens in background workers and may take a few minutes to fully appear in Neo4j."
    )
    return 0 if not failed else 2


if __name__ == "__main__":
    try:
        raise SystemExit(asyncio.run(main()))
    except KeyboardInterrupt:
        print("[ingest] aborted by user", file=sys.stderr)
        raise SystemExit(130)
