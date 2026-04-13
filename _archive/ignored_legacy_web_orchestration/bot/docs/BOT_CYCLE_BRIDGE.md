# Bot v0 — pont opérateur cycle (plan → exécution → revue)

Ce document décrit comment **l’opérateur** et le **bot** coopèrent sur **un seul cycle actif** à la fois, avec des artefacts réels sous `bot/state/`, sans API, sans navigateur automatisé, sans Telegram, sans Git.

## Les trois phases

| Phase | État typique (`cycle_state.json`) | Rôle opérateur |
|-------|-------------------------------------|----------------|
| **Plan** | `waiting_claude`, `claude_round=plan` | Obtenir un plan Claude → enregistrer avec `register-plan-response` (ou `register-claude-response`). |
| **Exécution** | `waiting_cursor` → `waiting_validation` | Travailler dans Cursor selon `cursor_handoff.md` → `register-cursor-finished` → `register-validation-result`. |
| **Revue** | `waiting_claude`, `claude_round=review` | Générer `claude_review_handoff.md` → revue Claude → `register-review-response` (ou `register-claude-review`). |

## Dossier d’un cycle (`bot/state/handoffs/<cycle_id>/`)

`<cycle_id>` est un **UUID** (un cycle à la fois, identifiant unique par `begin-cycle`).

### Évolution des fichiers (ordre habituel)

| Étape | Fichiers attendus / mis à jour |
|-------|--------------------------------|
| Après `begin-cycle` | `claude_intake.json` |
| Après `build-claude-handoff` | + `claude_handoff.md` (régénérable) |
| Après `register-plan-response` | + `claude_response.json` (plan), `cursor_execution.json` si `cursor_execute` |
| Après `build-cursor-handoff` | + `cursor_handoff.md` (régénérable) |
| Après travail Cursor (hors bot) | (rapports humains sous `reports/`) |
| Après `register-validation-result` (passed/skipped) | État → revue ; **même** `claude_response.json` contient encore le **plan** jusqu’à la revue |
| Après `build-review-handoff` | + `claude_review_handoff.md` (à générer **avant** d’écraser le plan si vous voulez garder la main sur l’ordre) |
| Après `register-review-response` | `claude_response.json` remplacé par le JSON **review** ; état → `completed` / `manual_gate` / `waiting_playwright` / `waiting_cursor` selon `verdict` |

Lister les chemins courants :

```powershell
.\bot-cli.ps1 show-cycle-files
```

## Commandes « pont » (CLI)

| Commande | Effet |
|----------|--------|
| `register-plan-response --file …` | Alias de **`register-claude-response`** : enregistre le plan, prépare `cursor_execution.json` si applicable. |
| `register-review-response --file …` | Alias de **`register-claude-review`** : enregistre la revue, transitions d’état selon `verdict`. |
| `build-review-handoff` | Écrit **`claude_review_handoff.md`** (rapports exécution/revue + état + contexte plan si `claude_response.json` est encore un plan). |
| `show-cycle-files` | Affiche les chemins absolus des fichiers du dossier du cycle actif. |

Les alias existent pour un **langage opérateur** stable (« plan / revue ») sans changer la logique du `CycleController`.

## Invariant important (revue)

Entre la fin de validation **réussie** et l’appel **`register-review-response`**, le fichier **`claude_response.json`** contient encore le **plan**.  
`build-review-handoff` lit ce plan pour le contexte. Dès que la revue est enregistrée, le même fichier contient la **revue** : regénérer le handoff de revue **après** coup ne montrera plus le plan (message explicite dans le Markdown).

## Module runtime

- **`bot/runtime/review_bridge.py`** — compilation déterministe du Markdown de revue + liste des fichiers du dossier cycle. Aucune inférence cachée : extraits **verbatim** bornés (même limite que `prompt_compiler`), chemins depuis `paths.json` + défauts.

## Windows

Préférer **`.\bot-cli.ps1`** à la racine du dépôt (voir `bot/docs/BOT_LOCAL_USAGE.md`, `bot/docs/BOT_WINDOWS_OPERATOR_FLOW.md`).

## Voir aussi

- `bot/docs/BOT_HANDOFFS.md` — handoffs Markdown plan / Cursor.
- `bot/examples/manual_cycle_walkthrough.md` — scénario JSON complet.
- `bot/examples/plan_response.example.json`, `bot/examples/review_response.example.json` — gabarits à copier.
