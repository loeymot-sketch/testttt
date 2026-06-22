# FoodKing — Délégation `codex-extension` (PLAN_REVIEW, EXÉCUTE, GPT_FINAL_AUDIT)

## SSOT 2026

- **PRIMARY PLAN_REVIEW** : `npm run codex:plan-review -- {TASK_ID}` → binaire **`@openai/codex`** + **`codex exec -m gpt-5.5-pro -c model_reasoning_effort="xhigh"`**, compte **ChatGPT / Pro** via `codex login`.
- **PRIMARY EXECUTE** : `npm run codex:complex -- {TASK_ID}` → `bash scripts/codex-extension-execute.sh` → binaire **`@openai/codex`** (`node_modules/.bin/codex`) + **`codex exec -m gpt-5.5-pro -c model_reasoning_effort="xhigh"`**, compte **ChatGPT / Pro** via `codex login` (**aucun** appel HTTP maison, **aucune** clé API dans le dépôt pour ce flux).
- **PRIMARY GPT_FINAL_AUDIT** : après `AUDIT_VERDICT: PASS`, `npm run codex:final-audit -- {TASK_ID}`.
- **FALLBACK** (si binaire / `exec` indispo après reprises) : sub-agent **`foodking-complex-implementer`** + `EXECUTE_DELEGATION: …` + `FALLBACK_REASON:`.
- **Instructions** (mandat, invariants) : `agents/codex-extension-instructions.md`.
- **Sorties** : `reports/audit/GPT_PLAN_REVIEW_{TASK_ID}.md`, `missions/{TASK_ID}/output_codex.json`, `reports/audit/GPT_SELF_AUDIT_{TASK_ID}.md`, `reports/audit/GPT_FINAL_AUDIT_{TASK_ID}.md`.

> Le dépôt **n’inclut plus** de connecteur *proxy* HTTP (`codex.runner.mjs`, `CODEX_API_BASE`, *portable* `dist/codex-portable/`). Tout a été retiré pour ne garder **que** le **CLI** officiel.

## Symétrie terminal (qualité maximale)

| Phase | PRIMARY | FALLBACK | Trace |
| -- | -- | -- | -- |
| PLAN_REVIEW | `codex-extension` | `foodking-complex-implementer` | `PLAN_REVIEW_CHANNEL:` |
| EXÉCUTE | `codex-extension` (ci-dessus) | `foodking-complex-implementer` | `EXECUTE_DELEGATION:` |
| AUDIT Claude | `bash scripts/foodking-claude-orchestrate.sh` | `foodking-planner-orchestrator` ou session Cursor Claude | `AUDIT_CHANNEL:` |
| GPT_FINAL_AUDIT | `codex-extension` | `foodking-complex-implementer` | `GPT_FINAL_AUDIT_CHANNEL:` |

Vérif. : `bash scripts/verify-orchestration-boucle.sh` — extrémité (option) : `VERIFY_BILLING_FULL=1` (1× fumigation `claude` + 1× `npm run codex:smoke`).

## Dépannage `api.responses.write` / 401 (extension Cursor ou `codex`)

> Fiche opération (causes, OpenAI, Cursor, scripts du dépôt) : **`docs/operations/CODEX_API_RESPONSES_401.md`**

Détails procéduaux : `.cursor/commands/run-cycle.md` Step 2 / 5 ; miroir formel : `.cursor/routing.md`.
