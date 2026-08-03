# Bloc d’ouverture de session — FoodKing (discipline max, ~1 minute)

> **But** : éviter de **redemander** à l’orchestrateur de « refaire la boucle ». Le chat **n’est pas** la SSOT. Ce bloc **remplace** la répétition orale par des **fichiers** + **1 commande agrégée**.

**Quand l’utiliser** : **chaque** nouvelle conversation Cursor, **chaque** onglet parallèle, **avant** d’écrire du code ou un plan.

---

## La commande unique (préférée)

```bash
npm run session:open      # tail log + verify:boucle + résumé ACTIVE_CYCLE + bloc à coller, en un seul appel
# ou plus rapide (sans verify:boucle) :
npm run session:open:fast
```

## Copier-coller (équivalent textuel — adapter `TASK_ID`)

```
[SESSION FoodKing — discipline]
0) J’ai lancé : npm run session:open  (ou j’ai lu manuellement les points 1→3 ci-dessous)
1) J’ai lu .cursor/ACTIVE_CYCLE.md (TASK_ID en cours ou vide).
2) J’exécute : bash scripts/agent-activity-log.sh tail 50
3) J’exécute : npm run verify:boucle
4) Phases = run-cycle.md (PLAN Claude→PLAN_REVIEW GPT→EXECUTE GPT→VALIDATE→AUDIT Claude→GPT_FINAL_AUDIT→CLOSE). Pas de close sans AUDIT_VERDICT: PASS + GPT_FINAL_AUDIT_VERDICT: PASS.
5) Mémoire = MEMORY_MATRIX (A code, B Graphiti/JSONL, C missions, D rapports) — pas de 5e store.
6) Parallèle = agent-activity-log start avant edit produit ; preflight-execute --scope avant d’éditer ; post-execute-guard avant VALIDATE ; done au close.
7) PLAN_REVIEW = npm run codex:plan-review ; EXÉCUTE produit = npm run codex:complex ; AUDIT = claude terminal sauf fallback Cursor Claude tracé ; SECOND AUDIT = npm run codex:final-audit.
Tâche demandée : <DÉCRIRE EN UNE LIGNE>
```

---

## Modèle Cursor de la session (critique production)

**Cursor ne choisit pas le modèle à ta place** : Auto / Composer / autre peut répondre dans le chat sans appliquer `routing.md`.

Pour un **cycle borné FoodKing en production** :

- La conversation où tu fais **PLAN** (rédaction du `plans/PLAN_*.md`, gates, pilotage des phases) doit utiliser le **modèle Claude** dans le sélecteur Cursor — **auteur formel du plan = Claude** (`.cursor/routing.md`, ligne PLAN).
- **Composer** n’est **pas** autorisé comme auteur de plan ni de gate brief (`routing.md`, § Hard Boundaries).
- **PLAN_REVIEW** et **EXECUTE** passent par GPT-5.5-pro / `xhigh` via `codex-extension`; Composer / `foodking-routine-implementer` ne fait pas d’implémentation produit en cycles de finition.
- **AUDIT** final commence par **Claude en terminal** en PRIMARY (`bash scripts/foodking-claude-orchestrate.sh audit` ou `audit-brief`). Si le terminal Claude échoue par quota, rate-limit, auth, réseau ou binaire absent, le repli Cursor Claude / `foodking-planner-orchestrator` est obligatoire et tracé avec `AUDIT_FALLBACK_REASON`.
- **SECOND AUDIT** final passe ensuite par GPT (`npm run codex:final-audit -- <TASK_ID>`). Le chat seul ne remplace pas ces audits.

En résumé : **tu donnes une tâche en une phrase**, l’agent **doit** enchaîner `session:open` → `run-cycle <TASK_ID>` → phases ; mais **« toujours Claude au premier message »** = **réglage du modèle dans Cursor + discipline**, pas une option cachée de l’IDE.

**Simulation détaillée** (checkpoints + fichiers lus par phase) : `reports/audit/SIMULATION_PARCOURS_PROD_CHECKPOINTS_2026-04-25.md`.

---

## Règles courtes (si tu ne colles pas le bloc)

| # | Faire | Ne pas faire |
|---|--------|----------------|
| 1 | Lire `ACTIVE_CYCLE.md` en premier | Inventer un second `TASK_ID` sans archiver l’ancien |
| 2 | `tail 50` sur le log d’activité | Éditer les mêmes fichiers qu’un autre agent réservé |
| 3 | `verify:boucle` (0 API) | Skip `AUDIT` / `GPT_FINAL_AUDIT` parce que « les tests passent » |
| 4 | Graphiti : `search_memory_facts` si MCP | Écrire des faits non audités dans Graphiti en plein EXÉCUTE |
| 5 | Tracer `PLAN_REVIEW_VERDICT`, `EXECUTE_DELEGATION`, `AUDIT_VERDICT`, `GPT_FINAL_AUDIT_VERDICT` | Mélanger rôles ou fermer sans double PASS |

---

## Liens

- **Index commandes** : `docs/orchestration/COMMAND_DECK.md` ← *si tu ne dois lire qu'un fichier après celui-ci*
- Parcours complet : `AGENTS.md` section *Parcours obligatoire*  
- Table de lecture : `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`  
- Multi-agents : `docs/orchestration/MULTI_AGENT_ORCHESTRATION.md`  
- Cycle détaillé : `.cursor/commands/run-cycle.md`

---

*Document minimal — toute évolution de procédure passe d’abord par `run-cycle.md` + matrice mémoire.*
