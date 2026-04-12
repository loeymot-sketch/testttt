# Bot v0 — handoffs Markdown (`claude_handoff.md` / `cursor_handoff.md`)

Ce document décrit les **artefacts Markdown** produits par le compilateur déterministe (`bot/runtime/prompt_compiler.py`) et les commandes CLI associées. Aucun appel réseau, aucune automatisation navigateur, aucun résumé « magique » : seulement des gabarits fixes et des extraits **verbatim** bornés.

## Fichiers générés

Pour le `cycle_id` actif (voir `.\bot-cli.ps1 show-state` ou `python bot/cli.py show-state`) :

| Fichier | Commande |
|---------|----------|
| `bot/state/handoffs/<cycle_id>/claude_handoff.md` | `.\bot-cli.ps1 build-claude-handoff` (racine repo ; ou `python bot/cli.py …` si Python est correct) |
| `bot/state/handoffs/<cycle_id>/cursor_handoff.md` | `.\bot-cli.ps1 build-cursor-handoff` |
| `bot/state/handoffs/<cycle_id>/claude_review_handoff.md` | `.\bot-cli.ps1 build-review-handoff` (phase revue uniquement — voir **`bot/docs/BOT_CYCLE_BRIDGE.md`**) |

`build-cursor-handoff` **échoue** si `cursor_execution.json` est absent (cas normal **avant** `register-claude-response` avec un plan qui envoie vers Cursor).

## Différence avec les paquets JSON

| Artefact | Rôle |
|----------|------|
| `claude_intake.json` | Données structurées + corps de sections (tronqués à la construction d’intake). Consommable par une future couche API. |
| `claude_response.json` | Plan ou review enregistré(e) après action humaine. |
| `cursor_execution.json` | Périmètre d’exécution + commandes de validation suggérées. |
| **`claude_handoff.md`** | **Vue lisible** pour coller dans un projet Claude : `cycle_id`, **phase** (état + tour plan/review), objectif, zones critiques (`risk_class`), surfaces (`files_allowed`), index des documents chargés dans l’intake, extraits **déterministes** des rapports `reports/*` (troncature fixe), type de sortie attendu, attentes de décision. |
| **`cursor_handoff.md`** | **Vue lisible** pour coller dans Cursor : périmètre d’exécution, fichiers autorisés, type de tâche, **recommandation de routing** issue de `bot/config/model_routing.json` (clé `cursor_execution`), snapshot `active_model_route` sur l’état, artefacts attendus, conditions d’arrêt, non-goals du plan. |

Les `.md` ne remplacent pas les JSON : ils **oriente** l’humain (et plus tard une couche d’automatisation) à partir des mêmes sources configurées (`bot/config/paths.json` + défauts dans `bot/runtime/init.py`).

## Usage manuel aujourd’hui (Windows / PowerShell)

À la racine du dépôt :

```powershell
cd C:\chemin\vers\FoodKing
.\bot-cli.ps1 show-state
.\bot-cli.ps1 build-claude-handoff
# chemin imprimé sur la sortie, ou ouvrir bot/state/handoffs/<cycle_id>/claude_handoff.md
```

Après enregistrement d’un plan Claude qui génère `cursor_execution.json` :

```powershell
.\bot-cli.ps1 build-cursor-handoff
```

Copie-colle le contenu dans la fenêtre de chat / Composer selon ton flux.

**Note :** `begin-cycle` exige `--task-id` et `--goal` (voir `bot/docs/BOT_LOCAL_USAGE.md`).

## Consommation future (couche navigateur / automation)

Une future couche pourra :

1. Lire `cycle_state.json` pour savoir où en est le cycle.
2. Régénérer à la demande `claude_handoff.md` / `cursor_handoff.md` (idempotents si l’état et les fichiers sources n’ont pas changé).
3. Pousser ces chaînes vers des surfaces UI (chat, ticket, CI artifact) **sans** interpréter le Markdown côté bot : le bot reste un **compilateur de contexte**, pas un agent.

Les garde-fous v0 restent valides : pas d’API externes dans `prompt_compiler.py`, pas d’exécution des commandes de validation par le bot.

## Voir aussi

- `bot/examples/manual_cycle_walkthrough.md` — cycle manuel incluant les commandes `build-*`.
- `bot/docs/BOT_LOCAL_USAGE.md` — tableau des commandes CLI.
