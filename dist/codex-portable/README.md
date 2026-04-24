# Codex API Complex Implementer — portable kit ( **legacy proxy** — PRIMARY repo path = `codex-extension` + CLI `codex` )

A small Node kit (`codex.runner.mjs` + `codex-load-env.mjs` + `codex.prompt.txt`) that turns any OpenAI-compatible proxy
(`{BASE}/responses` or `/v1/chat/completions`) into a **terminal-side complex implementer**, with the
exact same role/contract as a Cursor sub-agent. Battle-tested on FoodKing
(POS/Vue3 + Laravel/PHP) with **gpt-5.4** (default) and **gpt-5.5-high** / **gpt-5.5** / **gpt-5.5-pro** (overrides).

## What's in this folder

| File | Role |
|---|---|
| `codex-load-env.mjs` | Loads `.env` then `.env.codex` (surcharge) without overriding shell exports at launch. |
| `codex.runner.mjs` | The runner. Reads `missions/<TASK_ID>/input.json`, optional context files, sends the prompt (`CODEX_WIRE=responses` by default, or `chat` for stream + one-shot fallback), retries on 502/503/504/429 + on empty content, writes `missions/<TASK_ID>/output_codex.json`. |
| `codex.prompt.txt` | The system prompt. Defines the agent's role: *Complex Implementer*, hard constraints (pricing SSOT, status enum, branch isolation, post-commit dispatch, frozen zones), strict JSON output, `execution_trace.delegation = "codex-extension"`. |
| `codex.smoke.mjs` | Smoke test — verifies the proxy returns non-empty content. |
| `.env.codex.example` | Configuration template (API base, key, model, retries, etc.). |

## Why these 3 + prompt

Everything else (mission folder layout, npm scripts, audit hooks, governance
docs) is project-specific glue. Copy **`codex-load-env.mjs`**, **`codex.runner.mjs`**, and
`codex.prompt.txt` (plus `codex.smoke.mjs` optional) into the same directory (e.g. `agents/`), then point
`.env.codex` at your proxy.

## Install in another project

```bash
mkdir -p agents
cp codex-load-env.mjs agents/
cp codex.runner.mjs   agents/
cp codex.prompt.txt   agents/
cp codex.smoke.mjs    agents/        # optional
cp .env.codex.example .env.codex     # then fill in the values
```

Add to `package.json`:

```json
{
  "scripts": {
    "codex:complex": "node agents/codex.runner.mjs",
    "codex:smoke":   "node agents/codex.smoke.mjs"
  }
}
```

## Use

```bash
# 1) verify the proxy is reachable
npm run codex:smoke

# 2) create a mission
mkdir -p missions/MY-TASK-001
cat > missions/MY-TASK-001/input.json <<'EOF'
{
  "task_id": "MY-TASK-001",
  "objective": "Generate ...",
  "constraints": ["..."],
  "expected_output_format": "Return only the file body, no markdown."
}
EOF

# 3) run (default model = gpt-5.4, default wire = responses)
npm run codex:complex -- MY-TASK-001

# 4) inspect
cat missions/MY-TASK-001/output_codex.json
```

## Choose the output shape

- **Default (template)**: the runner wraps `input.json` inside `codex.prompt.txt`.
  The model returns a **strict JSON object** with `files_to_modify`,
  `implementation_steps`, `code_blocks`, `risks`, `notes`,
  `execution_trace.delegation = "codex-extension"`. Use this for orchestrator
  consumption.

- **Raw mode** (`CODEX_RAW_PROMPT=1`): `input.json` is sent as-is. Use this
  when the task itself dictates the output (e.g. "return only the .vue file").
  Auxiliary context files are still appended unless
  `CODEX_APPEND_AUX_WITH_RAW=0`.

## Choose the model

```bash
CODEX_MODEL_COMPLEX=gpt-5.5-pro npm run codex:complex -- MY-TASK-001
```

## Inject prior context (Graphiti, plan excerpt, etc.)

Drop any of these files inside `missions/<TASK_ID>/` before running — they are
auto-fused into the prompt as `## Prior context`:

```
graphiti_context.md
plan_excerpt.md
execute_brief.md
cycle_snapshot.md
```

Override the list with `CODEX_AUX_CONTEXT_FILES=a.md,b.md`.

## Reliability notes (learned the hard way on tokenclub-style proxies)

1. **Wire `chat`:** **Streaming is ON** when `CODEX_WIRE=chat`. Long non-stream
   responses time out at the Cloudflare gateway (HTTP 504). Streaming keeps the
   connection warm. With default **`CODEX_WIRE=responses`**, the runner uses
   `POST /responses` (no SSE); for very large generations, you may need **`chat`**
   + undici / no one-shot fallback — see `agents/codex.env.example` in the main repo.
2. **Retries cover 502/503/504/429** with exponential backoff (2s → 40s),
   max 8 attempts (`RETRY_MAX`).
3. **Empty content is also a retryable failure.** Some proxies return 200 with
   no `message.content` (rate budget hit, internal filter, etc.).
4. **Top-level `m` key normalisation.** Some proxies silently filter any user
   JSON whose top-level key is `m`. The runner rewrites `{"m": "..."}` to
   `{"instruction": "..."}` automatically. Disable with
   `CODEX_NO_NORMALIZE_M=1`.
5. **HTML 504/502 are caught** in `doOneShot` and surfaced as retryable API
   errors instead of unparseable text.

## Required Node version

Node 18+ (uses native `fetch`, `ReadableStream.getReader()`, `crypto.randomUUID`).
