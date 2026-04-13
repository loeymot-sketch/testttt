# Boucle automatique (`run_auto_cycle.ps1`)

Ce document décrit le **driver de boucle** qui enchaîne les commandes du bot (bridge navigateur + supervisor) **sans service Windows** ni daemon : tu lances le script quand tu veux, une seule instance à la fois.

## Fichier

- Script : `bot/scripts/run_auto_cycle.ps1`
- Dépendances : `bot-cli.ps1` à la racine du dépôt, Python, packages bot (dont Playwright si tu utilises `browser-run-step` côté Claude/Cursor navigateur).

## Quand utiliser `run_auto_cycle.ps1`

- Tu as déjà : **Playwright installé** (voir **`bot/docs/PLAYWRIGHT_SETUP_WINDOWS.md`**), `bot/browser_runner/browser_profiles.json` rempli, session Chromium persistante connectée à Claude (et éventuellement Cursor en mode Playwright).
- Sans Playwright, `browser-run-step` échoue avec `import_error_playwright` : le script s’arrête (code de sortie **1**) — c’est normal tant que l’environnement n’est pas prêt.
- Tu veux enchaîner automatiquement : **prepare → next-action → (optionnel) browser-run-step → run-supervisor-once** jusqu’à un état d’arrêt ou la limite d’itérations.

## Quand préférer les étapes manuelles

- Premier essai sur une machine : suivre `bot/browser_runner/README.md` (un `browser-run-step --no-write-inbox`, puis `browser-parse-last`, etc.).
- Débogage sélecteurs / mauvais onglet / quota : le runner s’arrête volontairement ; corriger la config ou la session, puis relancer **une** commande isolée.
- Phase **validation locale** (`action_kind: local_validation`) : le script **s’arrête** ; tu lances les tests, tu écris `validation.json`, puis tu relances le script ou `run-supervisor-once` à la main.
- **Cursor en mode presse-papiers** : le script s’arrête avec `cursor_clipboard_human_required` après copie du handoff ; complète le travail dans Cursor, crée `cursor_done.json`, relance.

## Comportement de la boucle

Chaque itération (dans l’ordre) :

1. `browser-bridge-prepare`
2. `browser-bridge-next-action`
3. Lecture de `bot/state/browser_bridge_next_action.json`
4. Si `action_kind` est `claude_project_browser` ou `cursor_agent` **et** qu’il n’y a pas de `blockers` : `browser-run-step` (écriture inbox activée)
5. `run-supervisor-once`
6. Relecture du cycle (`show-state`) pour détecter un état terminal

Paramètre **`-MaxSteps`** (défaut 50) : garde-fou obligatoire ; aucune boucle infinie.

Option **`-Config`** : chemin vers `bot_config.json` si tu n’utilises pas l’emplacement par défaut.

Exemple :

```powershell
cd C:\Users\openc\Desktop\testttt
.\bot\scripts\run_auto_cycle.ps1 -MaxSteps 40
```

## Conditions d’arrêt (sans « récupération cachée »)

Le script **s’arrête tout de suite** (sans retenter silencieusement) si :

| Motif | Suite possible |
|--------|----------------|
| FSM `completed`, `blocked`, `manual_gate`, `waiting_playwright`, `idle`, `preparing_intake` | Lire le résumé ; pour `completed`, cycle terminé côté bot. |
| Bridge `paused` ou `action_kind: paused` | Ouvrir `bot/state/browser_bridge_session.json`, remettre `paused` à `false` quand c’est bon, relancer. |
| `playwright_pending` / FSM `waiting_playwright` | Lancer les E2E FoodKing prévus par le plan, puis `register-playwright-result` (ou flux doc), **pas** le browser_runner. |
| `local_validation` | Exécuter les tests, écrire `validation.json`, relancer le script ou `run-supervisor-once`. |
| `bridge_blockers` (ex. handoff manquant) | `build-*-handoff`, vérifier les outbox, relancer. |
| Échec `browser-run-step` (JSON `ok: false`, quota, mauvaise conversation, Playwright absent, etc.) | Corriger config / session ; pas de retry magique dans le script. |
| `human_must_complete_cursor` | Mode clipboard : terminer dans Cursor, déposer `cursor_done.json`, relancer. |
| `max_steps_reached` | Augmenter `-MaxSteps` ou comprendre pourquoi le cycle n’avance pas (boucle logique). |

Code de sortie PowerShell : **0** pour les arrêts « attendus » (liste fixe dans le script), **1** pour erreurs techniques, garde-fou, blockers, échec supervisor, stdout non JSON du runner, etc.

## Quota / sortie mal formée

- **Quota** : le `browser-run-step` renvoie un statut explicite ; la boucle s’arrête. Attendre ou changer de compte / plan ; ne pas relancer en boucle aveugle.
- **JSON Claude illisible** : aucune écriture inbox fiable ; corriger la sortie côté Claude ou les sélecteurs, refaire un pas manuel si besoin.

## Reprise après `manual_gate` / `blocked`

Ce sont des **états FSM** : le bot ne les débloque pas tout seul. Lis `cycle_state.json` / `show-state`, traite la cause (revue humaine, correctif), puis utilise les commandes bot documentées (`force-*`, `reset-idle`, nouveau cycle, etc.) selon ton processus — **hors** de ce script tant que l’état n’est pas cohérent pour repartir.

## Ce qui reste humain ou hors runner

- Login initial dans le profil Chromium persistant.
- Validation CI locale et interprétation des échecs de tests.
- Playwright **application** FoodKing (pas le même que le navigateur Claude).
- Décisions produit / reprise après `manual_gate` ou `blocked`.

## Voir aussi

- `bot/docs/PLAYWRIGHT_SETUP_WINDOWS.md` — Playwright, `import_error_playwright`, `FOODKING_PYTHON`, encodage console.
- `bot/browser_runner/README.md` — profils, commandes `browser-*`.
- `bot/scripts/run_supervised_cycle.ps1` — parcours guidé pas à pas (sans boucle auto).
- `bot/browser_runner/guardrails.md` — limites de sûreté du runner navigateur.
