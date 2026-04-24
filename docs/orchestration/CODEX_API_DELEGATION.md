# FoodKing — Délégation `codex-extension` (EXÉCUTE complexe)

## SSOT 2026

- **PRIMARY** : `npm run codex:complex -- {TASK_ID}` → `bash scripts/codex-extension-execute.sh` → binaire **`@openai/codex`** (`node_modules/.bin/codex`) + **`codex exec`**, compte **ChatGPT / Pro** via `codex login` (**aucun** appel HTTP maison, **aucune** clé API dans le dépôt pour ce flux).
- **FALLBACK** (si binaire / `exec` indispo après reprises) : sub-agent **`foodking-complex-implementer`** + `EXECUTE_DELEGATION: …` + `FALLBACK_REASON:`.
- **Instructions** (mandat, invariants) : `agents/codex-extension-instructions.md`.
- **Sorties** : `missions/{TASK_ID}/output_codex.json`, `reports/audit/GPT_SELF_AUDIT_{TASK_ID}.md`.

> Le dépôt **n’inclut plus** de connecteur *proxy* HTTP (`codex.runner.mjs`, `CODEX_API_BASE`, *portable* `dist/codex-portable/`). Tout a été retiré pour ne garder **que** le **CLI** officiel.

## Symétrie terminal (économie Cursor)

| Phase | PRIMARY | FALLBACK | Trace |
| -- | -- | -- | -- |
| EXÉCUTE complexe | `codex-extension` (CI-dessus) | `foodking-complex-implementer` | `EXECUTE_DELEGATION:` |
| AUDIT | `bash scripts/foodking-claude-orchestrate.sh` | session Cursor (même rôle) | `AUDIT_CHANNEL:` |

Vérif. : `bash scripts/verify-orchestration-boucle.sh` — extrémité (option) : `VERIFY_BILLING_FULL=1` (1× fumigation `claude` + 1× `npm run codex:smoke`).

## Dépannage `api.responses.write` / 401 (extension Cursor ou `codex`)

- Le CLI utilise l’**API** OpenAI (endpoint *responses*) ; le compte doit avoir les **droits** côté plateforme (projet OpenAI, rôles) **ou** le panneau Cursor ne doit **pas** injecter de **clé restreinte** (sans scope *Responses*). Détails : `agents/codex-extension-instructions.md` (section *scopes*).

Détails procéduaux : `.cursor/commands/run-cycle.md` Step 2 / 5 ; miroir formel : `.cursor/routing.md`.
