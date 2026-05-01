# Audit « après » — nettoyage doc + fiche 401 + passage Claude (terminal)

**Date** : 2026-04-25

## Modifs appliquées (dépôt)

| Fichier | Contenu |
|---------|---------|
| `AGENTS.md` | P2 : retrait de « legacy proxy » ; seul le CLI `codex` est indiqué. |
| `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` | Règle 2 : suppression de « Proxy legacy = autre binaire » → alignement *pas de connecteur HTTP*. |
| `plans/MEGA_PLAN_ORCHESTRATION_2026-04-24.md` | Paragraphe *tokenclub* / proxy → formulation neutre (CLI, missions ciblées). |
| `docs/operations/CODEX_API_RESPONSES_401.md` | **Nouveau** : SSOT causes / OpenAI / Cursor / scripts `audit-bleed` + sanitize. |
| `docs/orchestration/CODEX_API_DELEGATION.md` | Lien unique vers la fiche opération (dépannage 401 raccourci). |
| `agents/codex-extension-instructions.md` | Titre de section : lien vers `CODEX_API_RESPONSES_401.md`. |
| `reports/audit/CLAUDE_CODEX_401_SANITIZE_AUDIT_BEFORE_2026-04-25.md` | Rapport **avant** (constats initiaux). |

## Passage **Claude Code** (terminal) — non interactif

- Commande : `bash scripts/foodking-claude-orchestrate.sh audit \"<prompt ciblé>\"` (voir exécution tracée dans l’environnement).
- **Sortie intégrale** : `reports/audit/CLAUDE_TERMINAL_CODEX_401_SANITIZE_2026-04-25.md` (génère aussi le préambule d’en-tête dans ce fichier).
- **Synthèse verdict Claude** : 401 = **hors dépôt** (compte OpenAI, rôles, clé *restricted*, Settings Cursor) ; **NEEDS_FOLLOWUP_HUMAN_OPENAI** ; docs lues = **pass** côté cohérence proxy/HTTP.

## Suite humaine (inchangé)

- Vérifier [platform.openai.com](https://platform.openai.com) (rôles, projet, clé) et **ne pas** laisser une clé *Platform* restreinte dans Cursor si le flux cible est `codex login` + Pro.
