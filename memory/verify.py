#!/usr/bin/env python3
"""Graphiti MCP smoke + domain coverage.

Spawns Graphiti MCP (stdio), calls get_episodes(group_ids, max_episodes=500),
counts completed episodes (heuristic: occurrences of \"uuid\" in the JSON blob),
then search_memory_facts on domain-tuned queries (one per memory/episodes/*.jsonl
file + extras). Optional --json writes reports/memory/verify_snapshot.json."""
from __future__ import annotations

import argparse
import asyncio
import json
import os
from datetime import datetime, timezone
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
START_SCRIPT = REPO_ROOT / ".cursor" / "mcp" / "start-graphiti-mcp.sh"
GROUP_ID = os.environ.get("GRAPHITI_GROUP_ID", "foodking")
INGEST_ENV = REPO_ROOT / "memory" / "ingest.env"
if INGEST_ENV.exists():
    os.environ.setdefault("GRAPHITI_ENV_FILE", str(INGEST_ENV))

SNAPSHOT_PATH = REPO_ROOT / "reports" / "memory" / "verify_snapshot.json"

# One signature query per JSONL domain file (P2) + smoke extras.
DOMAIN_QUERIES: list[tuple[str, str]] = [
    ("01_overview", "FoodKing project overview kiosk POS KDS stack"),
    ("02_invariants", "frozen zones BranchScope multi-tenant"),
    ("03_sync", "DomainEvent outbox correlation_id SYNC-001"),
    ("04_pricing", "pricing SSOT cents int no float"),
    ("05_fiscal", "NF525 Z report verifyChain audit_log hash"),
    ("06_kiosk", "kiosk wizard offline queue tacos"),
    ("07_pos", "POS park hold recall floorplan"),
    ("08_kds", "KDS bump station ItemAvailabilityChanged"),
    ("09_tasks", "V14 tasks G-1 G-2 cross-wave findings"),
    ("10_tests", "Vitest PHPUnit sentinels parity"),
    ("11_production", "production rollout G14-B preflight phases"),
    ("12_decisions", "ADR gate decision DispatchableAfterCommit"),
    ("13_agents", "run-cycle foodking-routine-implementer delegation"),
    ("14_conventions", "hooks i18n naming scope safety"),
    ("smoke_dispatch", "DispatchableAfterCommit trait"),
    ("smoke_snapshot", "composition_snapshot NF525"),
    ("smoke_openrouter", "OpenRouter Willow ingestion Graphiti"),
]


class MCPClient:
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
            limit=8 * 1024 * 1024,
        )
        asyncio.create_task(self._drain_stderr())
        await self._send(
            {
                "jsonrpc": "2.0",
                "id": 1,
                "method": "initialize",
                "params": {
                    "protocolVersion": "2024-11-05",
                    "capabilities": {"tools": {}},
                    "clientInfo": {"name": "verify", "version": "1.1"},
                },
            }
        )
        assert self.proc and self.proc.stdin
        self.proc.stdin.write(
            b'{"jsonrpc":"2.0","method":"notifications/initialized"}\n'
        )
        await self.proc.stdin.drain()

    async def _drain_stderr(self) -> None:
        assert self.proc and self.proc.stderr
        while True:
            line = await self.proc.stderr.readline()
            if not line:
                return

    async def _send(self, payload: dict) -> dict:
        assert self.proc and self.proc.stdin and self.proc.stdout
        async with self._lock:
            data = (json.dumps(payload) + "\n").encode()
            self.proc.stdin.write(data)
            await self.proc.stdin.drain()
            line = await asyncio.wait_for(self.proc.stdout.readline(), timeout=120)
            return json.loads(line.decode())

    async def call(self, name: str, args: dict) -> dict:
        self._next_id += 1
        resp = await self._send(
            {
                "jsonrpc": "2.0",
                "id": self._next_id,
                "method": "tools/call",
                "params": {"name": name, "arguments": args},
            }
        )
        return resp.get("result", {})

    async def close(self) -> None:
        if self.proc and self.proc.returncode is None:
            try:
                self.proc.stdin.close()
            except Exception:
                pass
            try:
                await asyncio.wait_for(self.proc.wait(), timeout=5)
            except Exception:
                self.proc.kill()


async def run_verify(json_out: bool) -> dict:
    out: dict = {"group_id": GROUP_ID, "queries": [], "episode_uuid_heuristic": 0}
    print("[verify] starting MCP server (cold start ~30s)...")
    c = MCPClient(["bash", str(START_SCRIPT)])
    await c.start()
    print("[verify] ready, querying...\n")
    try:
        eps = await c.call(
            "get_episodes", {"group_ids": [GROUP_ID], "max_episodes": 500}
        )
        eps_text = json.dumps(eps)
        n_eps = eps_text.count('"uuid"')
        out["episode_uuid_heuristic"] = n_eps
        print(f"=== Episodes visible in Neo4j (group={GROUP_ID}) ===")
        print(f"  count = {n_eps}\n")

        print("=== search_memory_facts (domain + smoke) ===")
        for key, q in DOMAIN_QUERIES:
            row: dict = {"key": key, "query": q, "fact_hits": 0, "error": None}
            try:
                r = await c.call(
                    "search_memory_facts",
                    {
                        "query": q,
                        "group_ids": [GROUP_ID],
                        "max_facts": 3,
                    },
                )
                txt = json.dumps(r, ensure_ascii=False)
                row["fact_hits"] = txt.count('"fact"')
                print(f"  [{row['fact_hits']:>2}] {key}: {q[:70]}…")
            except Exception as exc:
                row["error"] = str(exc)
                print(f"  [ERR] {key}: {exc}")
            out["queries"].append(row)
    finally:
        await c.close()
    print("\n[verify] done.")
    return out


def main() -> int:
    p = argparse.ArgumentParser(description="Graphiti memory verify")
    p.add_argument(
        "--json",
        action="store_true",
        help=f"Write structured snapshot to {SNAPSHOT_PATH}",
    )
    args = p.parse_args()
    data = asyncio.run(run_verify(args.json))
    if args.json:
        SNAPSHOT_PATH.parent.mkdir(parents=True, exist_ok=True)
        data["written_at"] = datetime.now(timezone.utc).isoformat()
        SNAPSHOT_PATH.write_text(
            json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
        )
        print(f"[verify] wrote {SNAPSHOT_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
