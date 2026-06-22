# Memory verification artifacts

- `verify_snapshot.json` — produced by `python3 memory/verify.py --json` (requires Graphiti MCP env).
- `jsonl_manifest.json` — produced by `bash scripts/memory-jsonl-manifest.sh` (offline, CI-friendly).

Commit manifests only when you want a baseline for `--check` drift detection.
