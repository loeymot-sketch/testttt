# Bot v0 — superviseur local (inbox / outbox)

Couche **semi-automatique** : une commande `run-supervisor-once` = **un tick** (pas de daemon, pas de boucle infinie). L’opérateur relance le tick après avoir déposé les fichiers.

Contraintes : **fichiers locaux uniquement** — pas d’API, pas de navigateur automatisé, pas Telegram, pas Git. **Un cycle actif** à la fois (`cycle_state.json`).

## Dossiers

| Rôle | Chemin (défaut, relatif à la racine du dépôt) |
|------|-----------------------------------------------|
| **Inbox** plan Claude | `bot/inbox/claude_plan/` — déposer un `*.json` (plan) |
| **Inbox** revue Claude | `bot/inbox/claude_review/` — déposer un `*.json` (review) |
| **Inbox** résultats Cursor | `bot/inbox/cursor_result/` — fichiers **nommés** (voir ci-dessous) |
| **Outbox** vers Claude | `bot/outbox/claude/` — le bot y recopie `claude_handoff.md` ou `claude_review_handoff.md` |
| **Outbox** vers Cursor | `bot/outbox/cursor/` — le bot y recopie `cursor_handoff.md` |

Chemins résolus : `python bot/cli.py show-dropzones` ou `.\bot-cli.ps1 show-dropzones`.

Configuration : **`bot/config/supervisor.json`**.

## Qui dépose quoi

| Acteur | Action |
|--------|--------|
| **Bot** (tick) | Régénère le handoff Markdown pertinent, le copie dans **outbox** si le contenu a changé. |
| **Humain (ou futur automate)** | Lit l’**outbox**, travaille avec Claude / Cursor, écrit la réponse dans **inbox** selon les règles de nom. |
| **Bot** (tick suivant) | Si un fichier inbox **valide** est présent : enregistre la transition du cycle, **déplace** le fichier consommé vers l’archive du cycle (pas d’écrasement silencieux). |

### Règles de nom (déterministes)

| Phase persistante | Fichier attendu dans inbox |
|-------------------|----------------------------|
| `waiting_claude` + `plan` | Le **premier** `*.json` par ordre alphabétique dans `claude_plan/` (les fichiers `*.example.json` sont **ignorés**). |
| `waiting_cursor` | Exactement **`cursor_done.json`** (nom configurable) dans `cursor_result/`. |
| `waiting_validation` | Exactement **`validation.json`** dans `cursor_result/`. |
| `waiting_claude` + `review` | Le **premier** `*.json` (hors `*.example.json`) dans `claude_review/`. |

## Avancement d’un tick

1. Lire l’état courant depuis `bot/state/cycle_state.json`.
2. Mettre à jour l’**outbox** correspondante (comparaison de contenu : pas de réécriture inutile).
3. Si l’inbox attendue ne contient pas encore le fichier requis : message explicite, **code 0** (rien à consommer).
4. Si un fichier est présent : valider JSON + champs obligatoires (`cycle_id`, `kind` pour cursor/validation, `response_kind` pour Claude, etc.).
5. Appeler le même contrôleur que la CLI (`register_claude_plan_response`, `register_cursor_finished`, `register_validation_result`, `register_claude_review_response`).
6. **Archiver** le fichier consommé sous :  
   `bot/state/handoffs/<cycle_id>/supervisor_inbox_archive/<sous-dossier>/`  
   avec un préfixe horodaté **UTC** sur le nom (`YYYYMMDDTHHMMSSZ_nom.json`) pour éviter toute collision.

**Un seul fichier consommé par tick** (pas d’enchaînement automatique plan → cursor dans le même processus).

## Récupération après erreur

- **JSON invalide** : message sur stderr, **code 1**, le fichier **reste** dans l’inbox (corriger ou supprimer manuellement).
- **cycle_id / task_id incorrect** : idem.
- Après succès, si le cycle a changé de phase, relancer `show-state` puis déposer le **prochain** artefact attendu.

## Fichiers d’exemple (non consommés automatiquement)

- `bot/examples/claude_plan.dropzone.example.json`
- `bot/examples/claude_review.dropzone.example.json`
- `bot/examples/cursor_result.dropzone.example.json`

**Validation** (phase `waiting_validation`) — modèle minimal pour `validation.json` :

```json
{
  "schema_version": 1,
  "kind": "validation_result",
  "cycle_id": "YOUR_CYCLE_UUID",
  "status": "passed",
  "detail": "php artisan test OK"
}
```

Valeurs de `status` : `passed` | `failed` | `skipped`.

## Commandes CLI

```powershell
.\bot-cli.ps1 show-dropzones
.\bot-cli.ps1 run-supervisor-once
```

Enchaînement typique : `begin-cycle` → plusieurs ticks tant que l’humain remplit les inbox entre chaque tick.

## Voir aussi

- `bot/docs/BOT_CYCLE_BRIDGE.md` — machine d’état et fichiers par phase.
- `bot/docs/BOT_LOCAL_USAGE.md` — toutes les commandes CLI.
