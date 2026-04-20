# T02b — Restauration WT vides (2026-04-20)

## task_id: T02b

## PRIMARY_MODEL: composer-2

## cycle scope: git restore fichiers tracked vidés en WT

Pré-exécution : `.cursor/hooks/safety-check.sh` **OK** dans les deux worktrees.  
Aucun fichier `M` avec WT vide n’avait un index au blob vide (`e69de29b…`) — **0 escalade** « index vide ».

## Worktree A (testttt) — fichiers détectés WT_EMPTY+index_OK

| Path | Index SHA | Action | Octets après restore |
|------|-----------|--------|----------------------|
| `.cursor/context/audit-context.md` | `f6a92fa9b86c8bd5bdd88a37f6fbb52a63ba9b89` | `git restore` | 2546 |
| `.cursor/mcp/start-litellm-bg.sh` | `b5dff22fecb8701d08ff94c782a40a1d0c559d8d` | `git restore` | 3314 |
| `.cursor/rules/claude.mdc` | `a20cd20f0994d8eccfb2ae8c3b72fcb195434a30` | `git restore` | 2942 |
| `app/Events/ItemAvailabilityChanged.php` | `d7f2056fa0210b741af977eade8a2b624952de33` | `git restore` | 2587 |
| `docs/orchestration/AGENT_ROLES.md` | `51f83ce3a8ebe365afd5629ee8e33dc877a959cf` | `git restore` | 963 |
| `phpunit.xml` | `2b6e515ffa15264bfb33c470244c7c9b7302a4bc` | `git restore` | 2834 |
| `public/js/pos-wizard.js` | `1a01565b3170c8f18be51709bbc715c599327bd6` | `git restore` | 287207 |
| `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` | `c8af95ecc9a2c0e616ad3ff6f915a028490fa8c0` | `git restore` | 10350 |
| `resources/js/components/admin/pos/PosComponent.vue` | `65ca1c51681e695a67950fbc33914ff5ac931c5b` | `git restore` | 106206 |
| `resources/js/components/frontend/auth/LoginComponent.vue` | `4a1000ec7c9c341382a78b4d19226e10812274cc` | `git restore` | 11040 |
| `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` | `9a5807eb893d3267135212cef8d34b3c45e94a91` | `git restore` | 17538 |
| `resources/js/languages/en.json` | `a67f8ed5f278a3c833ebfb8528dd96a8863118dc` | `git restore` | 65369 |
| `tests/Feature/HealthControllerTest.php` | `488b4400521e6a051c8d5634244dd9aea628807d` | `git restore` | 3554 |
| `tests/Unit/Domain/Order/OrderStateMachineTest.php` | `4756581ea8fd6ec2e849690f9932288970e2d28d` | `git restore` | 8102 |

Fichiers `M` avec contenu disque **non vide** (non restaurés, conformément au contrat) : `.cursor/rules/global-operating-principles.md`, `.cursor/skills/project-handoff/SKILL.md`, `cursor-export-new-account/...`, `reports/antigravity/playwright-latest.json`, `reports/compact_snapshot.md`.

## Worktree B (testttt-kiosk-p93) — idem

| Path | Index SHA | Action | Octets après restore |
|------|-----------|--------|----------------------|
| `.env.example` | `63a2de2b3a8a5a59ab4f10997fe65a2d534eae45` | `git restore` | 8079 |
| `app/Console/Kernel.php` | `b249407cf6905610d9535c00db693c6baa660c33` | `git restore` | 1551 |
| `app/Http/Controllers/Frontend/ItemController.php` | `6131992f50dc4fcca778e4575505d79bcbc118fe` | `git restore` | 5818 |
| `phpunit.xml` | `1e4b975204c773fe7772bd466d862b1888d84fd0` | `git restore` | 1713 |
| `tests/Feature/ItemResourceAllergensTest.php` | `1ac37febf1544531845ac93bf8b95b0935e1a7ea` | `git restore` | 3929 |
| `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php` | `46cb78de2c5f26052f18d08c836b4c0d61a06945` | `git restore` | 7314 |
| `tests/Feature/OutboxTest.php` | `9136a8ba09ff70d64ca6c3c73999abfde60f2546` | `git restore` | 6391 |

Les autres fichiers listés en `M` sous ce worktree avaient un WT **non vide** — non touchés.

## Fichiers où index aussi vide (escalade nécessaire)

Aucun détecté pour les chemins `M` + WT vide scannés.

## Sortie php artisan list (kiosk-p93)

**Invocation par défaut** (sans override d’environnement) — échec au boot :

```text
RuntimeException
BROADCAST_DRIVER must be explicitly set in production (expected: pusher|redis). Set BROADCAST_DRIVER in your .env file.
at app/Providers/AppServiceProvider.php:51
```

**Validation mécanique Kernel / console** (boot applicatif) — `APP_ENV=local` :

```text
Laravel Framework 9.52.21

Usage:
  command [options] [arguments]
...
Available commands:
  about                          Display basic information about your application
  clear-compiled                 Remove the compiled class file
  completion                     Dump the shell completion script
  db                             Start a new database CLI session
  docs                           Access the Laravel documentation
```

## Sortie php artisan schedule:list (kiosk-p93)

Environnement : `APP_ENV=local` (sinon même erreur `BROADCAST_DRIVER` que ci-dessus).

```text

  */15 * * * *  Closure at: app/Console/Kernel.php:23  Next Due: dans 11 minutes
  *    * * * *  php artisan foodking:outbox:rescue  Next Due: dans 36 secondes

```

## Tests PHPUnit lancés

| Suite | Résultat | Notes |
|-------|----------|--------|
| `./vendor/bin/phpunit --testsuite Unit --stop-on-failure` | **OK** | 153 tests, 253 assertions |
| `./vendor/bin/phpunit tests/Feature/Observability/ --stop-on-failure` | **FAIL** | 1 test ; `Target class [App\Http\Controllers\Frontend\KioskContextController] does not exist` (route/container) — hors scope T02b restore |
| `./vendor/bin/phpunit tests/Unit/Domain/Order/OrderStateMachineTest.php` | **OK** | 82 tests, 98 assertions |

Base de données : non signalée comme bloquante pour les suites ci-dessus (échec Observability = classe manquante, pas DB).

## Anomalies flagged

1. **`php artisan list` / `schedule:list`** sans `APP_ENV=local` : échec `AppServiceProvider` si `environment('production')` et `broadcasting.default` null — configuration `.env`, pas contenu vidé de `Kernel.php`.
2. **Observability Feature** : échec lié à `KioskContextController` introuvable pour l’autoload (fichier souvent non suivi `??` dans ce worktree) — à traiter hors restauration index.

## Audit status: PENDING_CLAUDE_REVIEW
