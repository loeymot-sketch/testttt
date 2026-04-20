# RUN — P11c_AVAILABILITY_TEST_BIDIRECTIONAL — 2026-04-20

TASK_ID: P11c_AVAILABILITY_TEST_BIDIRECTIONAL_2026-04-20
PLAN: tasks/execute-2026-04-20/08_EXECUTE_P11c_AVAILABILITY_TEST_BIDIRECTIONAL.md
PRIMARY_MODEL: Composer (foodking-routine-implementer)
RUNNER_MODE: single-session
STARTED_AT: 2026-04-20
SCOPE_FILES (whitelist) :
- tests/Feature/Admin/AvailabilityControllerTest.php (édition unique)

GATE_REQUIRED: NON (tests bornés, aucune modif backend)

## Pre-run evidence
- `AvailabilityControllerTest.php` : 1 seul test existant (ON→OFF avec rupture)
- 5 gaps documentés VERIFY-19 §V6 : OFF→ON, idempotence, branch_id=null fan-out, 403 cross-branch, outbox `domain_events`
- Backend production-safe inchangé (preuves VERIFY-19)
- **Aucune modif backend prévue** : tests décrivent la réalité observée (si écart, c'est un finding nouveau, pas un fix backend)

## Phases

### PLAN
- 4 étapes (lecture vérité, ajout 5 tests, validation, rapport)

### EXECUTE
- Ajout de 5 tests dans `tests/Feature/Admin/AvailabilityControllerTest.php` : réactivation OFF→ON, idempotence (`Event::assertNotDispatched`), fan-out `branch_id=null` pour admin `branch_id=0`, 403 cross-branch, outbox `domain_events` + canal.
- **Vérité terrain (read-only) documentée** :
  - `AvailabilityController::toggle` : si `branch_id` résolu est `null`, `$targetBranchIds = $scopeBranchIds` (pour utilisateur global `branch_id=0`, ce sont tous les IDs `branches` via `resolveScopedBranchIds`). Si `branch_id` cible non comprise dans le scope → JSON 403 `Branch scope denied.`.
  - `toggleBranchAvailability` : retourne `false` (no-op) si état déjà identique (`is_available` + `unavailable_reason` normalisé) → aucun event dans `$dispatches` → pas d’`ItemAvailabilityChanged` après commit.
  - `PersistItemAvailabilityChangedToOutbox` : pour un event branch-scoped, `$channels = ['private-branch.' . $event->branchId]` puis **`channel` en base = `json_encode($channels)`** (chaîne JSON représentant un **tableau** PHP à un élément, ex. `["private-branch.3"]`). `DomainEvent::$table = 'domain_events'`.
  - Pattern assertion outbox aligné sur `tests/Feature/Menu/AvailabilityServiceTest::test_listener_persists_branch_scoped_event_to_outbox` (`json_decode` du champ `channel`).
- Test outbox : `Bus::fake()` pour éviter exécution réelle des jobs file d’attente ; assertion `DispatchDomainEventsJob` dispatché ; **pas** de `Event::fake` sur `ItemAvailabilityChanged` afin que le listener outbox s’exécute.

### VALIDATE
- `php -l tests/Feature/Admin/AvailabilityControllerTest.php` → OK
- `vendor/bin/phpunit tests/Feature/Admin/AvailabilityControllerTest.php` → **OK (6 tests, 31 assertions)**

```
PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

......                                                              6 / 6 (100%)

Time: 00:00.996, Memory: 63.00 MB

OK (6 tests, 31 assertions)
```

Sortie `--testdox` :
```
Availability Controller (Tests\Feature\Admin\AvailabilityController)
 ✔ Staff can toggle item availability
 ✔ Staff can reactivate item availability
 ✔ Idempotent toggle does not dispatch event when state unchanged
 ✔ Admin global can toggle with branch id null fan out
 ✔ Branch staff cannot toggle other branch item 403
 ✔ Toggle persists domain event outbox with correct channel
```

- `git diff --stat tests/Feature/Admin/AvailabilityControllerTest.php` : **1 file changed, 267 insertions(+), 1 deletion(-)** (cible cycle uniquement ; le dépôt peut contenir d’autres fichiers modifiés hors ce cycle).

### AUDIT — Acceptance Tests (checklist plan)
- [x] PHPUnit fichier → 6 tests verts (1 existant + 5 nouveaux)
- [x] Diff stat cible : uniquement `AvailabilityControllerTest.php` pour ce travail
- [x] Aucune modif `app/`, `routes/`, `database/`, `config/` **dans ce cycle**
- [x] Idempotence : `Event::assertNotDispatched(ItemAvailabilityChanged::class)`
- [x] Cross-branch : `assertForbidden` (403) + message `Branch scope denied.`
- [x] Outbox : `assertDatabaseHas('domain_events', …)` + canal JSON array `private-branch.{id}`
- [x] Comportement aligné code réel ; pas de contournement backend

### Exit Criteria
- [x] 5 nouveaux tests verts ; test existant non régressé
- [x] Aucune modification backend
- [x] Rapport final présent (ci-dessous)

## Remediation Log
- Aucune tentative de remédiation : **premier passage VALIDATE OK** (0 retry).

## Final report

Task: P11c_AVAILABILITY_TEST_BIDIRECTIONAL_2026-04-20
Plan: tasks/execute-2026-04-20/08_EXECUTE_P11c_AVAILABILITY_TEST_BIDIRECTIONAL.md
Initial implementation: 5 tests Feature ajoutés sur la base du contrôleur et du listener outbox lus en read-only ; fan-out `branch_id=null` confirmé pour admin global.

Remediation attempts: 0

Final audit: PASSED
Critical zones touched: NONE
Human gate: NONE

Cycle: CLOSED after 0 remediation round(s)

### Findings nouveaux (écart doc / attente vs réalité)
- **Aucun** : le comportement observé (fan-out, idempotence, 403, format `channel` JSON array) correspondait aux attentes du plan après lecture du code.

### SCOPE_PRESSURE
- **Non déclenché** : seuls fichiers touchés par ce cycle — `tests/Feature/Admin/AvailabilityControllerTest.php` et ce rapport d’exécution.

---

## AUDIT Claude orchestrateur — 2026-04-20

**Date audit** : 2026-04-20 ~19:25 (immédiatement post-CLOSED subagent)
**Auditor** : Claude (parent orchestrator)

### Vérifications indépendantes

1. **Re-run PHPUnit** (indépendant subagent) :
   ```
   PHPUnit 9.6.29 by Sebastian Bergmann and contributors.
   ✔ Staff can toggle item availability
   ✔ Staff can reactivate item availability
   ✔ Idempotent toggle does not dispatch event when state unchanged
   ✔ Admin global can toggle with branch id null fan out
   ✔ Branch staff cannot toggle other branch item 403
   ✔ Toggle persists domain event outbox with correct channel
   OK (6 tests, 31 assertions)
   ```
   → ✅ 6/6 tests reproductibles (925ms)

2. **Diff stat scope** : `1 file changed, 267 insertions(+), 1 deletion(-)` sur `tests/Feature/Admin/AvailabilityControllerTest.php` — conforme whitelist.

3. **Git status post-cycle** — modifications détectées dans le repo :
   - ✅ `tests/Feature/Admin/AvailabilityControllerTest.php` (in-scope)
   - ⚠️  `app/Http/Middleware/ValidateKioskLocale.php` — **HORS CYCLE P11c**
   - ⚠️  `tests/Feature/KioskMultiBranch/KioskLocaleMiddlewareTest.php` — **HORS CYCLE P11c**
   - (autres fichiers M/?? préexistants)

   **Analyse des fichiers Kiosk apparus** :
   - Tags du diff : `[C5 / K-9 ADR-4]` → cycle observability kiosk K-9 (totalement étranger à P11c qui visait Availability admin)
   - Pattern de code : `Log::channel('observability')->info('kiosk_locale.format_invalid', ...)` → instrumentation observability sur middleware kiosk
   - Subagent a explicitement nié toute touche `app/` (output : "Aucune modification de `app/`, `routes/`, `database/`, `config/`")
   - Le diff porte uniquement sur la branche kiosk locale (sans rapport avec `AvailabilityController` ni `domain_events` ni admin)

   **Conclusion** : ces 2 fichiers proviennent d'un **dev parallèle** (utilisateur ou autre cycle K-9 hors orchestration P11c). **Hors périmètre audit P11c**, aucun lien fonctionnel avec ce cycle. À traiter dans un cycle distinct si besoin (potentiellement un futur `P11_KIOSK_LOCALE_OBSERVABILITY` rattaché à K-9).

4. **Vérité terrain re-vérifiée** :
   - `AvailabilityController::toggle` + `resolveScopedBranchIds` + `toggleBranchAvailability` non modifiés
   - `PersistItemAvailabilityChangedToOutbox` non modifié
   - `DomainEvent` non modifié
   - Backend production-safe préservé conformément à la contrainte stricte "no backend modification"

5. **Qualité du diff test** :
   - Use statements ajoutés correctement (`DomainEvent`, `ItemBranchAvailability`, `Bus`, `Carbon`, `EventType`)
   - Pattern `setUp()` du test existant reproduit dans chaque nouveau test
   - Assertion `Bus::fake()` + `Bus::assertDispatched(DispatchDomainEventsJob::class)` astucieuse pour test 6 (laisse listener outbox s'exécuter)
   - `Event::fake([...])` correctement scopé pour ne pas faker outbox listener

### Verdict orchestrateur

**Cycle P11c_AVAILABILITY_TEST_BIDIRECTIONAL** : **CLOSED — PASSED** (0 remédiation, 0 finding nouveau)

- Scope respecté strictement (tests-only, aucun backend touché par le subagent)
- Tests verts reproductibles (6/6, 31 assertions, 925ms)
- Vérité terrain documentée explicitement dans le rapport (format `channel` JSON, fan-out global, etc.)
- Pattern outbox test correct (Bus::fake + assertDispatched, pas Event::fake sur ItemAvailabilityChanged)
- Couvre les 5 gaps VERIFY-19 §V6
- Aucun finding nouveau découvert (réalité = attente)

### ⚠️  Note hors-cycle (à traiter séparément)
2 fichiers `app/Http/Middleware/ValidateKioskLocale.php` + `tests/Feature/KioskMultiBranch/KioskLocaleMiddlewareTest.php` sont apparus modifiés dans le workspace pendant la fenêtre temporelle du cycle P11c (mtime 19:19-19:21). Le diff porte des tags `[C5 / K-9 ADR-4]` (cycle observability kiosk K-9). **NON imputable au subagent P11c** (déni explicite + pattern de code étranger). À investiguer comme dev parallèle ou cycle K-9 séparé.

### Phase Completion (final)
| Phase | Done |
|---|---|
| PLAN | [x] |
| EXECUTE | [x] |
| VALIDATE | [x] |
| AUDIT | [x] |

**STATUS FINAL : CLOSED — PASSED — 0 remediation**
