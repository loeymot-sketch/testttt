# Windows — boucle opérateur assistée (bot v0)

Ce document décrit la **boucle humaine** locale : pas d’API Claude/Cursor, pas d’automatisation navigateur, pas Telegram, pas Git. Le bot ne fait qu’écrire l’état et les fichiers sous `bot/state/`.

## Où sont les fichiers

- **État machine** (un seul cycle actif) : `bot/state/cycle_state.json`
- **Handoffs par cycle** : `bot/state/handoffs/<cycle_id>/`

**Important :** `<cycle_id>` est un **UUID** généré à chaque `begin-cycle`. Le `task_id` (ex. `BOT-SMOKE-001`) apparaît **dans** le JSON d’état et dans les handoffs, mais **n’est pas** le nom du dossier sur disque. Pour trouver le dossier après un cycle : `.\bot-cli.ps1 show-state` → copier `cycle_id` → ouvrir `bot\state\handoffs\<cycle_id>\`.

## Prérequis

- Python 3.10+ utilisable (voir `bot/docs/BOT_LOCAL_USAGE.md` si `python` ouvre le Microsoft Store).
- Racine du dépôt : **`.\bot-cli.ps1`** fixe `PYTHONPATH` et lance `bot/cli.py`.

## Script guidé (recommandé)

Depuis la racine du dépôt :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\bot\scripts\run_manual_cycle.ps1
```

Le script demande `task_id` et `goal`, enchaîne `reset-idle` → `begin-cycle` → `build-claude-handoff`, ouvre **`claude_handoff.md`**, puis affiche les **étapes exactes** suivantes.

## Boucle manuelle détaillée

### 1. Démarrer un cycle

```powershell
cd C:\chemin\vers\FoodKing
$env:PYTHONPATH = "."
python bot/cli.py reset-idle
python bot/cli.py begin-cycle --task-id "BOT-SMOKE-001" --goal "Description du but" --trigger human
```

Ou : `.\bot-cli.ps1 reset-idle` puis `.\bot-cli.ps1 begin-cycle ...`.

Effet : `cycle_state.json` → `waiting_claude` ; création de `bot/state/handoffs/<cycle_id>/claude_intake.json`.

### 2. Générer le handoff Claude (Markdown)

```powershell
python bot/cli.py build-claude-handoff
```

Fichier : `claude_handoff.md` (même dossier que l’intake). Contenu attendu : `cycle_id`, **phase courante**, objectif, documents / rapports (extraits bornés), type de sortie attendu, attentes de décision — voir `bot/docs/BOT_HANDOFFS.md`.

### 3. Envoyer à Claude (manuel)

Copier-coller le Markdown dans ton **projet Claude** ou ton flux habituel. Aucune intégration API en v0.

### 4. Enregistrer la réponse Claude (plan JSON)

Sauvegarder le JSON **plan** dans le dossier du cycle, par ex. :

`bot/state/handoffs/<cycle_id>/claude_plan.json`

### 5. Enregistrer le plan auprès du bot

```powershell
python bot/cli.py register-claude-response --file bot/state/handoffs/<cycle_id>/claude_plan.json
```

Si `suggested_next_actor` est `cursor_execute`, le bot écrit **`cursor_execution.json`** et passe à **`waiting_cursor`**.

### 6. Générer le handoff Cursor

```powershell
python bot/cli.py build-cursor-handoff
```

**Sans l’étape 5**, cette commande **échoue** (pas de `cursor_execution.json`) — c’est normal.

`cursor_handoff.md` doit contenir : `cycle_id`, périmètre d’exécution, type de tâche, routing recommandé (config), artefacts attendus, conditions d’arrêt, non-goals — voir `bot/runtime/prompt_compiler.py`.

### 7. Envoyer à Cursor (manuel)

Ouvrir `cursor_handoff.md`, coller dans **Cursor** / Composer selon ton usage.

### 8. Après travail Cursor

```powershell
python bot/cli.py register-cursor-finished
python bot/cli.py register-validation-result --status passed --detail "…"
```

Puis revue Claude (`register-claude-review`), etc. — cycle complet décrit dans `bot/examples/manual_cycle_walkthrough.md`.

### 9. Fermer ou abandonner

```powershell
python bot/cli.py reset-idle
```

### 10. Phase revue (après validation réussie)

Quand `show-state` affiche `waiting_claude` et `claude_round: review` :

```powershell
python bot/cli.py build-review-handoff
python bot/cli.py show-cycle-files
```

Puis enregistrer la revue Claude : `register-review-response` (alias de `register-claude-review`). Détails et ordre des fichiers : **`bot/docs/BOT_CYCLE_BRIDGE.md`**.

## Lien avec la semi-automatisation future

Une couche ultérieure pourra enchaîner les mêmes **appels CLI** et la lecture des **mêmes fichiers** ; ce document reste la référence du comportement **déterministe** attendu avant toute automatisation navigateur.

## Voir aussi

- `bot/docs/BOT_LOCAL_USAGE.md` — tableau des commandes et dépannage Windows.
- `bot/docs/BOT_HANDOFFS.md` — rôle des `.md` vs JSON.
- `bot/examples/manual_cycle_walkthrough.md` — exemple de JSON plan / review.
