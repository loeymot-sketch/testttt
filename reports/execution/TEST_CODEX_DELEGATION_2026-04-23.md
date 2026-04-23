# Rapport de test — délégation API complexe + contexte (Graphiti)

**Date (UTC) :** 2026-04-23  
**Dépôt :** FoodKing web (workspace testttt)  
**Objectif :** valider le routage **routine (sub-agent Composer)** vs **complexe (proxy `gpt-5.4-pro` + runner)**, la fusion de contexte (fichiers `missions/…/`), et l’alignement sémantique sur `app-complex-implementer.md` via `agents/codex.prompt.txt`.

## Configuration référencée

| Élément | Fichier / emplacement |
|--------|------------------------|
| Prompt « substitut » du complex implementer | `agents/codex.prompt.txt` |
| Runner (HTTP, fusion contexte, normalisation clé `m`) | `agents/codex.runner.mjs` |
| Orchestration + Graphiti | `docs/orchestration/CODEX_API_DELEGATION.md` |
| Rôle sub-agent (repli) | `.cursor/agents/app-complex-implementer.md` |
| Entrée cycle (EXECUTE) | `.cursor/commands/run-cycle.md` (Step 2) |

Variables typiques (non versionnées) : `CODEX_API_BASE`, `CODEX_API_KEY`, `CODEX_MODEL_COMPLEX=gpt-5.4-pro` dans `.env` / `.env.codex` (modèle vérifié côté tableau de bord facturation).

## Routage (attendu)

- **Tâche classée *routine*** → `foodking-routine-implementer` (Cursor Task).
- **Tâche *complexe* (autorisée au plan, EXECUTE)** → d’abord `npm run codex:complex -- {TASK_ID}` (recommandé : `npm run codex:fast` si 503 en stream) ; repli : `foodking-complex-implementer` si l’API est indisponible.
- **Graphiti (session Cursor avec MCP)** : requêtes `search_memory_facts` (groupe `foodking`) pliées manuellement dans `missions/{TASK_ID}/graphiti_context.md` avant d’exécuter le runner (le terminal n’invoque pas le MCP seul). Après changement structurant, enregistrement `add_memory` / JSONL+ingest selon `graphiti-memory.mdc`.

*Épisode Graphiti d’orchestration enregistré (session 2026-04-23) : « FoodKing: codex API delegation 2026-04-23 », groupe `foodking` (queued).*

## Scénario de test SMOKE

1. **Mission** : `missions/SMOKE-CODEX-DELEG-001/`
2. Fichiers :
   - `input.json` — tâche **synthétique** (pas de vrai patch code) exigeant un JSON de validation.
   - `graphiti_context.md` — bref contexte (simulé) pour prouver la **fusion** dans le prompt.
3. Commande exécutée (machine de développement) :
   - `npm run codex:fast -- SMOKE-CODEX-DELEG-001`  
   Le script `agents/codex.env-fast.mjs` force `CODEX_DISABLE_STREAM=1` (one-shot) pour limiter les échecs stream / 503 du proxy.
4. **Résultat** : le fichier de sortie `missions/SMOKE-CODEX-DELEG-001/output_codex.json` a été généré avec le contenu conforme à la consigne de test, démontrant **communication API + application du schéma de sortie** demandé.

Exemple (tronqué) de signal attendu côté modèle pour ce test d’intégration (structure variable selon requête exacte) :

- Correspondance **status** / **delegation** = `codex-terminal` telle qu’imposée par l’`input.json` de test (validé en exécution le 2026-04-23).

5. Outil **`npm run codex:prepare -- {TASK_ID}`** : génère le squelette (input + stub `graphiti_context.md` etc.) ; vérification manuelle : `agents/codex.prepare.mjs` s’exécute sans erreur (usage documenté).

## Contraintes connues

- **503** intermittents sur l’host proxy : reprises intégrées + mode `codex:fast` ; si échec prolongé, repli explicite sur le sub-agent `foodking-complex-implementer`.
- **Clé `m` en JSON user** : renommée côté runner en `instruction` (proxy) sauf `CODEX_NO_NORMALIZE_M=1`.

## Verdict

| Critère | Statut |
|--------|--------|
| Même cahier des charges conceptuel qu’`app-complex-implementer` côté prompt | OK (prompt + doc) |
| Portage **Graphiti / plan** par fichiers de mission + fusion | OK (schéma + test avec `graphiti_context.md`) |
| Routage routine / complexe documenté (AGENTS, run-cycle, Primer) | OK |
| Test terminal avec sortie appliquée (SMOKE) | OK (run du 2026-04-23) |

*Ce rapport n’est pas un audit de code produit : il ne valide que la chaîne d’orchestration et l’**existence d’une réponse exploitable** du proxy sur la tâche de fumée.*
