# Bot v0 — usage locale (FoodKing)

Ce document décrit l’exploitation **réelle** du calque opérateur fichier (`bot/cli.py` + `bot/runtime/`) **sans** API Claude, **sans** Telegram, **sans** Playwright automatisé, **sans** Git.

## Prérequis

- **Python 3.10+** sur le `PATH`.
- Exécution depuis la **racine du dépôt** FoodKing (`testttt/`, etc.).
- Variable d’environnement **`PYTHONPATH`** incluant la racine du dépôt (le package `bot` doit être importable).

## Fichiers de configuration

Les fichiers **réels** (déjà présents dans ce dépôt pour v0) :

| Fichier | Rôle |
|---------|------|
| `bot/config/bot_config.json` | Racine dépôt (`repo_root` vide = inféré depuis l’emplacement de ce fichier), chemins `state_dir`, `logs_dir`, `cycle_state_path`, chemins vers les autres JSON. |
| `bot/config/paths.json` | Fichiers sources pour l’**intake** Claude (rapports, `CLAUDE.md`, `MEMORY.md`). Les sections `docs/ops/*` et `docs/roles/*` par défaut viennent du merge avec les défauts dans `bot/runtime/init.py` si tu ne les redéfinis pas ici. |
| `bot/config/model_routing.json` | Métadonnées de **routing** (tiers logiques) — aucun appel réseau en v0. |
| `bot/config/telegram.json` | **Désactivé** (`enabled: false`). Remplace `REPLACE_ME` si tu branches l’envoi plus tard ; **ne commite pas** de vrais secrets. |

Pour repartir des exemples versionnés : copier `*.example.json` vers les noms ci-dessus (déjà fait pour ce repo).

### Inférence de `repo_root`

Si `bot_config.json` contient `"repo_root": ""` (chaîne vide ou absente), la racine dépôt est :

`parent(parent(parent(bot_config.json)))` → pour `…/FoodKing/bot/config/bot_config.json`, racine = `…/FoodKing`.

Tu peux aussi fixer `"repo_root": "C:\\\\chemin\\\\vers\\\\FoodKing"` (absolu) sous Windows.

## Windows PowerShell (racine du repo)

```powershell
cd C:\Users\openc\Desktop\testttt
$env:PYTHONPATH = "."
python bot/cli.py show-state
```

Même principe pour toutes les commandes ci-dessous.

## Commandes CLI

| Commande | Effet |
|----------|--------|
| `begin-cycle` | Nouveau `cycle_id`, écrit `bot/state/cycle_state.json`, génère **`bot/state/handoffs/<cycle_id>/claude_intake.json`**, état **`waiting_claude`** (round `plan`). Un seul cycle actif : refus si l’état n’est pas `idle` ou `completed`. |
| `show-state` | Affiche l’état JSON courant. |
| `register-claude-response --file …` | Lit un JSON **plan** (`response_kind: plan`), enregistre `claude_response.json`, transition vers **`waiting_cursor`**, **`manual_gate`**, **`blocked`**, ou **`waiting_playwright`** selon le contenu. |
| `register-cursor-finished` | `waiting_cursor` → **`waiting_validation`**. |
| `register-validation-result --status passed|failed|skipped` | `failed` → **`blocked`** ; `passed` / `skipped` → **`waiting_claude`** (`claude_round: review`). |
| `register-playwright-result --status passed|failed|skipped` | Idem logique Playwright → review ou **`blocked`**. |
| `register-claude-review --file …` | JSON **review** (`response_kind: review`), verdict `APPROVED` → **`completed`**, etc. |
| `force-blocked --reason …` | Forçage opérateur. |
| `force-manual-gate --reason …` | Forçage opérateur. |
| `reset-idle` | Réinitialise la machine à **`idle`** (nouvel objet d’état vide). |

Option globale : **`--config PATH`** vers un autre `bot_config.json` (défaut : `bot/config/bot_config.json`).

## Où sont les fichiers de handoff

Pour un `cycle_id` donné :

- **Entrée Claude (intake généré)** : `bot/state/handoffs/<cycle_id>/claude_intake.json`
- **Réponse Claude (plan ou review, collée / produite hors bot)** : `bot/state/handoffs/<cycle_id>/claude_response.json` (écrasé à chaque enregistrement plan/review)
- **Paquet Cursor** : `bot/state/handoffs/<cycle_id>/cursor_execution.json` (écrit quand le plan pointe vers l’exécution Cursor)

État courant (un seul fichier) : **`bot/state/cycle_state.json`**

Logs (répertoire réservé, v0 ne remplit pas encore automatiquement) : **`bot/logs/`**

## Exemple de cycle manuel complet

Voir **`bot/examples/manual_cycle_walkthrough.md`** (étapes + exemples de JSON).

## Dépannage

- **`Refusing begin-cycle: current state is …`** : termine ou abandonne le cycle (`reset-idle` si approprié).
- **`Expected state …`** : tu as appelé une transition dans le désordre ; vérifie avec `show-state`.
- **JSON invalide** : le CLI quitte avec un message explicite sur stderr.
