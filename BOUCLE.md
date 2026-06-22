# Boucle de travail obligatoire (FoodKing)

Ce fichier est à la **racine du dépôt** pour qu’il soit visible **sans qu’un humain dise « lis AGENTS.md »**.

## Règle

Toute modification **code produit** (`app/`, `resources/`, `routes/`, `database/`, `tests/` produit, `config/`, etc.) dans un contexte de **mission identifiable** doit passer par la **boucle bornée** :

1. Lire **`.cursor/ACTIVE_CYCLE.md`** (reprise ou pas de cycle fantôme).
2. Définir un **`TASK_ID`** et un fichier **`plans/PLAN_[TASK_ID]_*.md`** (voir **`.cursor/context/plan-context.md`**).
3. Suivre **`.cursor/commands/run-cycle.md`** : Steps **0 → 5** (PLAN → PLAN_REVIEW → EXECUTE → VALIDATE → AUDIT → CLOSE).
4. **EXECUTE** complexe : canal primaire **`codex-extension`** (CLI `codex` Pro) — voir **`AGENTS.md`** et **`docs/orchestration/CODEX_API_DELEGATION.md`**.
5. Traces : **`reports/post_execute_latest.log`**, réservations **`scripts/agent-activity-log.sh`**.

## Commandes (routine)

```bash
npm run verify:boucle
bash scripts/agent-activity-log.sh tail 50
# Puis, pour une mission bornée :
run-cycle <TASK_ID>
```

## Lecture complète

Le contrat détaillé est dans **`AGENTS.md`** (§0 et §1 — **Parcours obligatoire**).  
Ce fichier `BOUCLE.md` est un **rappel d’entrée** ; il ne remplace pas `AGENTS.md`.
