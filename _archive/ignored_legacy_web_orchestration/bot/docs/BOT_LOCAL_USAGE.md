# Bot v0 — usage locale (FoodKing)

Ce document décrit l’exploitation **réelle** du calque opérateur fichier (`bot/cli.py` + `bot/runtime/`) **sans** API Claude, **sans** Telegram, **sans** Playwright automatisé, **sans** Git.

## Prérequis

- **Python 3.10+** installé (réellement — pas seulement l’alias Microsoft Store).
- Exécution depuis la **racine du dépôt** FoodKing (`testttt/`, etc.).
- Variable d’environnement **`PYTHONPATH`** incluant la racine du dépôt (le package `bot` doit être importable).

### Windows : lanceur recommandé (`bot-cli.ps1`)

À la racine du dépôt, préférer :

```powershell
.\bot-cli.ps1 show-state
```

Ce script fixe `PYTHONPATH`, ignore le stub `WindowsApps\python.exe`, et cherche Python sous `%LOCALAPPDATA%\Programs\Python\…`.  
Sous **cmd.exe** : `bot-cli.cmd show-state`.  
Forcer un exécutable : `$env:FOODKING_PYTHON = "C:\chemin\vers\python.exe"`.  
Si l’exécution de scripts est bloquée : `powershell -NoProfile -ExecutionPolicy Bypass -File .\bot-cli.ps1 show-state` (ou utilisez **`bot-cli.cmd`**).

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
| `register-plan-response --file …` | **Alias** de `register-claude-response` (même comportement). |
| `register-cursor-finished` | `waiting_cursor` → **`waiting_validation`**. |
| `register-validation-result --status passed|failed|skipped` | `failed` → **`blocked`** ; `passed` / `skipped` → **`waiting_claude`** (`claude_round: review`). |
| `register-playwright-result --status passed|failed|skipped` | Idem logique Playwright → review ou **`blocked`**. |
| `register-claude-review --file …` | JSON **review** (`response_kind: review`), verdict `APPROVED` → **`completed`**, etc. |
| `register-review-response --file …` | **Alias** de `register-claude-review`. |
| `force-blocked --reason …` | Forçage opérateur. |
| `force-manual-gate --reason …` | Forçage opérateur. |
| `reset-idle` | Réinitialise la machine à **`idle`** (nouvel objet d’état vide). |
| `build-claude-handoff` | Écrit **`claude_handoff.md`** sous le `cycle_id` courant (Markdown déterministe pour collage humain). |
| `build-cursor-handoff` | Écrit **`cursor_handoff.md`** (nécessite **`cursor_execution.json`**). |
| `build-review-handoff` | Écrit **`claude_review_handoff.md`** (état **`waiting_claude`** + **`claude_round=review`** ; voir `bot/docs/BOT_CYCLE_BRIDGE.md`). |
| `show-cycle-files` | Liste les chemins absolus des fichiers sous **`bot/state/handoffs/<cycle_id>/`**. |
| `run-supervisor-once` | **Un tick** du superviseur : met à jour **outbox**, consomme **au plus un** fichier **inbox** valide, archive si succès. Voir **`bot/docs/BOT_SUPERVISOR_FLOW.md`**. |
| `show-dropzones` | Affiche les chemins **inbox/outbox** résolus depuis **`bot/config/supervisor.json`**. |

Option globale : **`--config PATH`** vers un autre `bot_config.json` (défaut : `bot/config/bot_config.json`).

Handoffs Markdown : voir **`bot/docs/BOT_HANDOFFS.md`**.  
Pont cycle plan / exécution / revue : **`bot/docs/BOT_CYCLE_BRIDGE.md`**.  
Superviseur inbox/outbox : **`bot/docs/BOT_SUPERVISOR_FLOW.md`**.  
Boucle opérateur Windows (assistée) : **`bot/docs/BOT_WINDOWS_OPERATOR_FLOW.md`** — script **`bot/scripts/run_manual_cycle.ps1`**.

## Où sont les fichiers de handoff

Pour un `cycle_id` donné :

- **Entrée Claude (intake généré)** : `bot/state/handoffs/<cycle_id>/claude_intake.json`
- **Réponse Claude (plan ou review, collée / produite hors bot)** : `bot/state/handoffs/<cycle_id>/claude_response.json` (écrasé à chaque enregistrement plan/review)
- **Paquet Cursor** : `bot/state/handoffs/<cycle_id>/cursor_execution.json` (écrit quand le plan pointe vers l’exécution Cursor)
- **Handoff revue Claude** : `bot/state/handoffs/<cycle_id>/claude_review_handoff.md` (écrit par `build-review-handoff` au bon moment)

État courant (un seul fichier) : **`bot/state/cycle_state.json`**

Logs (répertoire réservé, v0 ne remplit pas encore automatiquement) : **`bot/logs/`**

## Exemple de cycle manuel complet

Voir **`bot/examples/manual_cycle_walkthrough.md`** (étapes + exemples de JSON).

## Dépannage

- **`Python est introuvable` / message Microsoft Store** : sous Windows, `python` peut pointer vers un **stub** dans `…\WindowsApps\python.exe` sans installation réelle. Corriger en installant Python (ex. `winget install -e --id Python.Python.3.12`), puis **fermer et rouvrir** le terminal pour recharger le `PATH`. En secours, appeler l’exécutable complet, par ex.  
  `& "$env:LOCALAPPDATA\Programs\Python\Python312\python.exe" bot/cli.py show-state`  
  Option utile : **Paramètres → Applications → Alias d’exécution des applications** → désactiver les alias **python.exe** / **python3.exe** pour éviter le conflit avec le Store.
- **`Refusing begin-cycle: current state is …`** : termine ou abandonne le cycle (`reset-idle` si approprié).
- **`Expected state …`** : tu as appelé une transition dans le désordre ; vérifie avec `show-state`.
- **JSON invalide** : le CLI quitte avec un message explicite sur stderr.
