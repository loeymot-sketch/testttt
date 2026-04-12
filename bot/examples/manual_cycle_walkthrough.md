# Walkthrough — un cycle manuel complet (bot v0)

Hypothèse : dépôt cloné sous `C:\Users\you\Desktop\testttt`, PowerShell, `PYTHONPATH=.` à la racine.

Les **`cycle_id`** ci-dessous sont des **exemples** ; en vrai, `begin-cycle` imprime le JSON d’état : copie la valeur `cycle_id` pour les fichiers JSON suivants.

---

## 1. Point de départ

```powershell
cd C:\Users\you\Desktop\testttt
$env:PYTHONPATH = "."
python bot/cli.py reset-idle
python bot/cli.py show-state
```

Tu dois voir `"state": "idle"`.

---

## 2. Démarrer un cycle (intake généré)

```powershell
python bot/cli.py begin-cycle --task-id T-DEMO-01 --goal "Corriger un test flaky sur le filtre Order" --trigger human
```

- Le CLI affiche le **nouvel état** (`waiting_claude`, `claude_round: plan`).
- Sur stderr : chemin vers **`claude_intake.json`**.

Ouvre :

`bot/state/handoffs/<cycle_id>/claude_intake.json`

Tu y verras les sections (rapports, `CLAUDE.md`, `MEMORY.md`, bundle `docs/ops` / `docs/roles` selon config).

---

## 3. Simuler la réponse Claude (plan)

Crée `bot/state/handoffs/_demo_plan.json` (remplace `YOUR_CYCLE_ID` par le vrai UUID) :

```json
{
  "schema_version": 1,
  "cycle_id": "YOUR_CYCLE_ID",
  "task_id": "T-DEMO-01",
  "response_kind": "plan",
  "received_at": "2026-04-11T15:00:00+00:00",
  "objective": "Isoler le test et ajuster les assertions.",
  "scope_non_goals": ["Ne pas toucher au moteur de prix"],
  "risk_class": "tests_only",
  "suggested_next_actor": "cursor_execute",
  "test_stance": "Kimi-test",
  "verdict": null,
  "human_decision": "GO",
  "files_allowed": ["tests/Feature/ExampleTest.php"]
}
```

Enregistre :

```powershell
python bot/cli.py register-claude-response --file bot/state/handoffs/_demo_plan.json
```

Effet :

- `claude_response.json` est écrit sous le même `cycle_id`.
- **`cursor_execution.json`** est généré (chemins + commandes de validation par défaut).
- État → **`waiting_cursor`**.

---

## 4. Fin d’exécution Cursor (humain)

Après travail dans Cursor :

```powershell
python bot/cli.py register-cursor-finished
```

État → **`waiting_validation`**.

---

## 5. Résultat de validation

Succès :

```powershell
python bot/cli.py register-validation-result --status passed --detail "php artisan test OK"
```

État → **`waiting_claude`**, `claude_round` → **`review`**.

---

## 6. Revue Claude (simulation)

Fichier `bot/state/handoffs/_demo_review.json` :

```json
{
  "schema_version": 1,
  "cycle_id": "YOUR_CYCLE_ID",
  "task_id": "T-DEMO-01",
  "response_kind": "review",
  "received_at": "2026-04-11T16:00:00+00:00",
  "objective": "Revue post-tests",
  "scope_non_goals": [],
  "risk_class": "tests_only",
  "suggested_next_actor": "cursor_execute",
  "test_stance": "Kimi-test",
  "verdict": "APPROVED",
  "human_decision": null,
  "files_allowed": []
}
```

```powershell
python bot/cli.py register-claude-review --file bot/state/handoffs/_demo_review.json
```

État → **`completed`**.

---

## 7. Fermer le cycle côté machine

```powershell
python bot/cli.py reset-idle
```

---

## Variante Playwright (sans lancer le navigateur)

Si le **plan** avait `"suggested_next_actor": "playwright"`, l’état passerait à **`waiting_playwright`**. Tu pourrais alors enregistrer manuellement :

```powershell
python bot/cli.py register-playwright-result --status passed --detail "CI job #123 green"
```

puis enchaîner avec une **review** comme ci-dessus. Le bot v0 **n’exécute** pas Playwright ; il ne fait qu’enregistrer l’état et les fichiers.
