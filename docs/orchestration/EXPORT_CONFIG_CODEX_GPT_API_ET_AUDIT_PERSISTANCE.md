# OpenAI / Codex — config persistante (pilotage)

Ce fichier **remplace** l’ancienne doc sur le *runner* HTTP (proxy+clé), **supprimé** du dépôt.

## Ce qu’il faut sur une machine

| Élément | Rôle |
|--------|------|
| `npm install` dans le dépôt | installe `@openai/codex` → `node_modules/.bin/codex` |
| `codex login` | *Sign in with ChatGPT* (compte Pro si besoin) |
| `~/.codex/config.toml` | Config du **CLI** (ex. `wire_api`, modèle) — **pas** dans le git |
| **Pas** de `OPENAI_API_KEY` / `CODEX_API_KEY` héritée pour forcer l’auth ChatGPT (sinon 401 *scopes* si clé *restricted*) |

Outils d’aide (repo) : `npm run codex:doctor`, `npm run codex:audit-bleed`, `agents/codex-extension-instructions.md`.

**Audit (Anthropic)** : `bash scripts/foodking-claude-orchestrate.sh` (voir `AGENTS.md`).

**Mémoire long-terme (JSONL / Graphiti)** : `docs/orchestration/MEMORY_MATRIX.md` ; pas de *store* parallèle.
