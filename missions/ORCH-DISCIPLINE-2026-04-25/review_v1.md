# Mission de revue (second avis GPT-5.5 pro) — Plan v1 discipline d'orchestration

## Contexte (court)

Repo FoodKing (Laravel + Vue), gouvernance multi-agents déjà en place :

- `AGENTS.md` (parcours obligatoire), `.cursor/commands/run-cycle.md` (PLAN→EXECUTE→VALIDATE→AUDIT→CLOSE, plafond 5 remediations).
- `MEMORY_MATRIX.md` : 4 stores autorisés (A code / B Graphiti+JSONL / C missions / D rapports). Pas de 5ᵉ store.
- `cross-agent-sync.mdc` (alwaysApply) : `scripts/agent-activity-log.sh tail/start/done` pour réserver les périmètres.
- EXECUTE complexe : CLI `codex` (Pro) via `npm run codex:complex`. AUDIT : `claude` terminal (`foodking-claude-orchestrate.sh`).
- Cycles déjà fonctionnels (POS_V4_W0..W2, MEGA-EXEC). Le problème n'est PAS l'absence de doctrine, c'est **l'adoption** : aucun garde-fou refuse une édition si l'agent oublie `start` ou `verify:boucle`.

## Plan v1 proposé (à challenger)

Trois ajouts, zéro nouveau store, zéro contournement de invariants existants :

### 1. `scripts/session-open.sh`
Agrège en UN appel l'état session (pas un nouveau concept, juste agrégation) :
- `bash scripts/agent-activity-log.sh tail 50`
- `npm run verify:boucle`
- résumé court de `.cursor/ACTIVE_CYCLE.md` (TASK_ID, PHASE, PLAN_FILE)
- affiche le bloc copiable de `docs/orchestration/SESSION_OPENING_ENFORCEMENT.md`

But : qu'un agent qui démarre n'ait qu'UNE commande à lancer pour avoir tout le contexte SSOT.

### 2. `scripts/preflight-execute.sh <TASK_ID>`
Garde-fou exécutable AVANT toute édition produit :
- exit 2 si pas de réservation `start` active pour ce `TASK_ID` (lit `agent-activity-log active`).
- exit 3 si dernier `reports/post_execute_latest.log` ne contient pas `EXECUTE_DELEGATION:` (preuve délégation manquante du run précédent).
- exit 0 sinon.

À appeler dans `scripts/codex-extension-execute.sh` (avant `codex exec`) et à documenter en Step 2 de `run-cycle.md`.

### 3. `docs/orchestration/COMMAND_DECK.md`
Page unique "tableau de bord" pour humain : état du cycle actif (lien `ACTIVE_CYCLE`), commandes les plus utiles (5 lignes), liens vers les 3 SSOT (`AGENTS.md`, `run-cycle.md`, `MEMORY_MATRIX.md`). PAS un nouveau store.

## Questions explicites pour ton second avis (réponds en bullets courts)

1. **Risque de blocage** : ces deux garde-fous (preflight) peuvent-ils créer plus de friction que la discipline qu'ils protègent (ex. cas légitimes où start n'a pas de sens) ? Si oui, lesquels et comment les bypasser proprement ?
2. **Ai-je raté un fix plus impactant** ? Ex : un hook git pre-commit ? Un wrapper autour de `codex exec` qui force le contexte ? Une modif de `run-cycle.md` plus directe ?
3. **Anti-patterns** dans v1 ? (par ex : un script qui maquille la complexité au lieu de la résoudre, ou qui crée une dépendance fragile)
4. **Multi-agents parallèles** : sur deux conversations Cursor en simultané touchant des fichiers DIFFÉRENTS, le plan v1 garantit-il vraiment l'absence de surprise ? Si non, qu'est-ce qui manque ?
5. **Zéro-faute en prod** : qu'est-ce que tu changerais pour atteindre le niveau "élite" demandé par l'humain (vraiment, pas marketing) ?

Réponds en français, format structuré, max 60 lignes. Pas de code, juste raisonnement + recommandations actionnables.
