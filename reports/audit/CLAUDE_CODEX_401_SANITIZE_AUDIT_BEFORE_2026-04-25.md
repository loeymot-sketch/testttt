# Audit « avant » — 401 `api.responses.write` (Codex) + bruit documentaire

**Date** : 2026-04-25 (repo FoodKing)  
**Contexte** : l’erreur vient d’**OpenAI** (rôles org/projet, clé *restricted* sans portée Responses) ; le dépôt a déjà retiré le runner HTTP/proxy. Il reste des **mentions héritées** qui peuvent prêter à confusion.

## Constats (grep ciblé)

| Zone | Problème |
|------|----------|
| `AGENTS.md` P2 | Ligne 20 : « CLI `codex` **ou legacy proxy** » — **obsolète** (proxy supprimé). |
| `GLOBAL_SYSTEM_PRIMER.md` §1 | Règle 2 : « **Proxy legacy** = autre binaire » — **trompeur** après retrait du kit HTTP. |
| `plans/MEGA_PLAN_ORCHESTRATION_2026-04-24.md` | Paragraphe « proxy GPT (*tokenclub*)… » — **héritage** ; ne reflète plus le dépôt. |
| Rapports d’exé historiques (`reports/execution/*`) | Réfs à `codex.runner`, `tokenclub` — **archives** ; non bloquant pour l’orchestration courante. |

## Ce qui est déjà correct (SSOT)

- `npm run codex:complex` → seul le **CLI** `codex`.
- `scripts/codex-sanitize-env-for-codex-cli.sh` : unset `OPENAI_API_KEY` / `CODEX_*` héritées pour le sous-processus.
- `scripts/codex-audit-env-bleed.mjs` : signalement sans exposer de secrets.
- `docs/orchestration/CODEX_API_DELEGATION.md` : déjà orienté « pas de connecteur HTTP » + dépannage 401 en bref.

## Prochaine étape (après)

- Corriger P2 / PRIMER / plan méga, ajouter fiche `docs/operations/CODEX_API_RESPONSES_401.md`.
- Passe **Claude terminal** : `foodking-claude-orchestrate.sh audit` (prompt ciblé) → fichier sous `reports/audit/`.
- Rapport **après** : `CLAUDE_CODEX_401_SANITIZE_AUDIT_AFTER_2026-04-25.md`.
