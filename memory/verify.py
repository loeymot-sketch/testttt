#!/usr/bin/env python3
"""Quick verification: spawns Graphiti MCP (stdio), calls get_episodes(group_ids,
max_episodes=500), counts completed episodes (heuristic: occurrences of \"uuid\" in
the JSON blob), then search_memory_facts on 8 sample queries."""
from __future__ import annotations
import asyncio, json, os, sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
START_SCRIPT = REPO_ROOT / ".cursor" / "mcp" / "start-graphiti-mcp.sh"
GROUP_ID = os.environ.get("GRAPHITI_GROUP_ID", "foodking")
INGEST_ENV = REPO_ROOT / "memory" / "ingest.env"
if INGEST_ENV.exists():
    os.environ.setdefault("GRAPHITI_ENV_FILE", str(INGEST_ENV))


class MCPClient:
    def __init__(self, cmd):
        self.cmd, self.proc, self._next_id, self._lock = cmd, None, 1, asyncio.Lock()

    async def start(self):
        self.proc = await asyncio.create_subprocess_exec(
            *self.cmd,
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
            limit=8 * 1024 * 1024,  # 8 MiB lines for big get_episodes responses
        )
        asyncio.create_task(self._drain_stderr())
        await self._send({
            "jsonrpc": "2.0", "id": 1, "method": "initialize",
            "params": {"protocolVersion": "2024-11-05",
                       "capabilities": {"tools": {}},
                       "clientInfo": {"name": "verify", "version": "1.0"}}
        })
        self.proc.stdin.write(b'{"jsonrpc":"2.0","method":"notifications/initialized"}\n')
        await self.proc.stdin.drain()

    async def _drain_stderr(self):
        while True:
            line = await self.proc.stderr.readline()
            if not line:
                return
            # silence (we only care about JSON-RPC responses)

    async def _send(self, payload):
        async with self._lock:
            data = (json.dumps(payload) + "\n").encode()
            self.proc.stdin.write(data); await self.proc.stdin.drain()
            line = await asyncio.wait_for(self.proc.stdout.readline(), timeout=120)
            return json.loads(line.decode())

    async def call(self, name, args):
        self._next_id += 1
        resp = await self._send({"jsonrpc": "2.0", "id": self._next_id,
                                  "method": "tools/call",
                                  "params": {"name": name, "arguments": args}})
        return resp.get("result", {})

    async def close(self):
        if self.proc and self.proc.returncode is None:
            try: self.proc.stdin.close()
            except: pass
            try: await asyncio.wait_for(self.proc.wait(), timeout=5)
            except: self.proc.kill()


async def main():
    print("[verify] starting MCP server (cold start ~30s)...")
    c = MCPClient(["bash", str(START_SCRIPT)])
    await c.start()
    print("[verify] ready, querying...\n")
    try:
        eps = await c.call("get_episodes", {"group_ids": [GROUP_ID], "max_episodes": 500})
        eps_text = json.dumps(eps)
        n_eps = eps_text.count('"uuid"')
        print(f"=== Episodes visible in Neo4j (group={GROUP_ID}) ===")
        print(f"  count = {n_eps}\n")

        queries = [
            "DispatchableAfterCommit trait",
            "composition_snapshot NF525",
            "SYNC-001 KDS ItemAvailabilityChanged",
            "production rollout plan synchronization",
            "frozen zones PaymentService",
            "tacos N viandes wizard",
            "POS park hold recall",
            "OpenRouter Willow ingestion",
        ]
        print(f"=== search_memory_facts samples ===")
        for q in queries:
            try:
                r = await c.call("search_memory_facts", {"query": q,
                                                          "group_ids": [GROUP_ID],
                                                          "max_facts": 3})
                txt = json.dumps(r, ensure_ascii=False)
                # Count facts by counting "fact" keys
                n_facts = txt.count('"fact"')
                print(f"  [{n_facts:>2}] {q}")
            except Exception as exc:
                print(f"  [ERR] {q}: {exc}")
    finally:
        await c.close()
    print("\n[verify] done.")


if __name__ == "__main__":
    asyncio.run(main())
