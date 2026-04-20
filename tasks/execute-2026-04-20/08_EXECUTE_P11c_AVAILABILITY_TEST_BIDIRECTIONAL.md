# EXECUTE — P11c_AVAILABILITY_TEST_BIDIRECTIONAL — 2026-04-20

## Status
**STATUS:** `READY_TO_LAUNCH`
**GATE_REQUIRED:** **NON** (tests bornés, aucune modif code applicatif)
**VAGUE:** V3 (P1 hardening — plan §1.2 ligne 61)
**BLOCKING:** Aucun

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.2 ligne 61 + §2 V3 ligne 148
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-19-02
- `reports/review/VERIFY_19_AVAILABILITY_TOGGLE_ROUTE_2026-04-20.md` §V6 lignes 153-158 (gaps explicites)

## Constat factuel pré-cycle (vérifié read-only)

**Test existant** (`tests/Feature/Admin/AvailabilityControllerTest.php`) :
- 1 seul test : `test_staff_can_toggle_item_availability` (ON→OFF avec rupture)
- Couvre : permission `items_edit`, `x-api-key`, persistance `item_branch_availability`, event `ItemAvailabilityChanged::forBranch`

**Gaps documentés VERIFY-19 §V6** (à combler) :
1. **OFF→ON (réactivation)** — manque
2. **Idempotence** (re-toggle même état → 0 event dispatched) — manque
3. **`branch_id=null` fan-out scope** — manque
4. **403 cross-branch** (`resolveScopedBranchIds` rejette si user branch ≠ payload branch) — manque
5. **Assertion outbox** (`DomainEvent` row créée + canal `private-branch.X`) — manque

**Backend production-safe inchangé** (preuve VERIFY-19 §V1-V5) → ce cycle **n'a strictement aucun impact code applicatif**, c'est de l'enrichissement de tests sur logique déjà couverte par cycle V1 #04 (BUSINESS_RULES_DOC_SYNC §5) + cycle V1 #06 (UI admin émetteur).

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `Composer` (AGENTS.md:16 — "isolated tests, documentation")
- **SUBAGENT:** `foodking-routine-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
- `tests/Feature/Admin/AvailabilityControllerTest.php` (enrichissement, +5 tests)

### SCOPE_FILES (whitelist stricte)
- `tests/Feature/Admin/AvailabilityControllerTest.php` (édition uniquement, **un seul fichier**)
- `reports/execution/RUN_P11c_AVAILABILITY_TEST_BIDIRECTIONAL_2026-04-20.md` (création)

### SUBSYSTEMS_OFF_LIMITS (strict — leçons cycles 02, 05, 07)
- `app/Http/Controllers/Admin/AvailabilityController.php` (intact — backend production-safe)
- `app/Http/Requests/Admin/AvailabilityToggleRequest.php` (intact)
- `app/Services/Menu/AvailabilityService.php` (intact)
- `app/Models/`, `app/Events/`, `app/Listeners/`, `app/Providers/`
- `routes/api.php`, `database/migrations/`, `config/`
- Toute autre suite de tests (`tests/Unit/`, `tests/Feature/Menu/`, etc.)
- `phpunit.xml` (pas d'env override, le précédent cycle a déjà géré ça)
- `package.json`, `composer.json`, lockfiles
- Toute touche frontend, kiosk, POS

## Invariants at Risk
- **Aucun** — c'est uniquement de l'enrichissement test sur backend déjà prouvé production-safe.
- Risque potentiel : si un nouveau test révèle un **bug réel** dans le backend (ex. l'idempotence n'est pas vraiment respectée, le fan-out cross-branche persiste), alors le subagent doit **STOP + escalader** comme finding nouveau (pas modifier le backend pour rendre vert le test).

## Dependencies
- Aucune (cycle indépendant)

## Plan bref

### Étape 1 — Lire (vérité terrain)
- `tests/Feature/Admin/AvailabilityControllerTest.php` (intégral 82 lignes — pattern existant)
- `app/Http/Controllers/Admin/AvailabilityController.php` (read-only — pour identifier les comportements à tester : `resolveScopedBranchIds`, `toggleBranchAvailability`, `$didChange` no-op, `DB::afterCommit`)
- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` (read-only — pour vérifier comment outbox `DomainEvent` est créé : table, channel, payload)
- `app/Models/DomainEvent.php` (existence + table name)
- 1-2 autres tests Feature existants utilisant Outbox/DomainEvent (pattern d'assertion DB) — chercher via Grep `domain_events|DomainEvent::class|OutboxTest` dans `tests/Feature/`

### Étape 2 — Ajouter 5 tests dans `AvailabilityControllerTest.php`

Pattern préservé (`use RefreshDatabase`, `setUp()` identique, `withHeader('x-api-key', ...)`) :

#### Test 2 : `test_staff_can_reactivate_item_availability` (OFF→ON)
- Pré : item indisponible (`is_available=false, unavailable_reason='rupture'`) — créer `ItemBranchAvailability` row directement via factory ou seed
- Action : POST toggle avec `is_available=true`
- Assert : 200 + DB row mise à jour `is_available=1, unavailable_reason=null` + Event::dispatched

#### Test 3 : `test_idempotent_toggle_does_not_dispatch_event_when_state_unchanged`
- Pré : item déjà `is_available=false, unavailable_reason='rupture'`
- Action : POST toggle avec **mêmes valeurs** (`is_available=false, unavailable_reason='rupture'`)
- Assert : 200 + DB row identique + **Event::assertNotDispatched(ItemAvailabilityChanged::class)**

#### Test 4 : `test_admin_global_can_toggle_with_branch_id_null_fan_out`
- Pré : 2 branches créées + admin `branch_id=0` (global)
- Action : POST toggle avec `branch_id=null`
- Assert : 200 + DB rows créées dans `item_branch_availability` pour les 2 branches + 2 events dispatched (un par branche)

> **Note** : si `branch_id=null` + admin global = pas implémenté ainsi (à vérifier en lisant le controller `resolveScopedBranchIds`), adapter le test pour matcher le comportement réel **sans modifier** le code. Si le comportement réel est différent du test, **documenter dans rapport** comme nouveau finding.

#### Test 5 : `test_branch_staff_cannot_toggle_other_branch_item_403`
- Pré : 2 branches A (id=1) + B (id=2), staff `branch_id=A`
- Action : POST toggle avec `branch_id=B.id`
- Assert : **403** (`resolveScopedBranchIds` rejette + abort 403 'Branch scope denied')

#### Test 6 : `test_toggle_persists_domain_event_outbox_with_correct_channel`
- Pré : staff branch A, item, no fake event (laisser le listener tourner — ou Event::fake mais aussi mock le listener)
- Approche : utiliser `Event::fakeExcept([])` ou ne pas faker du tout pour que le listener `PersistItemAvailabilityChangedToOutbox` s'exécute réellement
- Action : POST toggle ON→OFF
- Assert : `assertDatabaseHas('domain_events', [...])` avec `channel = ["private-branch.{branchA.id}"]` (ou format JSON équivalent — vérifier exact dans le listener)

> **Note** : pour ce test 6, vérifier si la table s'appelle `domain_events` ou autre (lire migration). Si `Outbox` est storé différemment, adapter sans inventer.

### Étape 3 — Validation
- `vendor/bin/phpunit tests/Feature/Admin/AvailabilityControllerTest.php` → 6 tests verts (1 existant + 5 nouveaux)
- `php -l tests/Feature/Admin/AvailabilityControllerTest.php`
- `git diff --stat` (preuve scope respect — un seul fichier)
- `git status --short` (preuve aucun fichier hors whitelist)

### Étape 4 — Rapport
`reports/execution/RUN_P11c_AVAILABILITY_TEST_BIDIRECTIONAL_2026-04-20.md` avec gabarit Final report + diff résumé + sortie phpunit complète.

## Acceptance Tests
- [ ] `vendor/bin/phpunit tests/Feature/Admin/AvailabilityControllerTest.php` → **6 tests verts** (existant préservé + 5 nouveaux)
- [ ] `git diff --stat` montre **uniquement** `tests/Feature/Admin/AvailabilityControllerTest.php` modifié
- [ ] **Aucun** fichier `app/`, `routes/`, `database/`, `config/` modifié
- [ ] Test 3 (idempotence) utilise `Event::assertNotDispatched` (preuve no-op event)
- [ ] Test 5 (cross-branch) retourne strictement 403
- [ ] Test 6 (outbox) assert sur `domain_events` table avec format channel correct
- [ ] Si comportement réel ≠ comportement attendu → documenté comme finding, **PAS** de modif backend

## Exit Criteria
- [ ] 5 nouveaux tests ajoutés, tous verts
- [ ] Test existant non régressé
- [ ] Aucune modification backend
- [ ] `reports/execution/RUN_P11c_AVAILABILITY_TEST_BIDIRECTIONAL_2026-04-20.md` avec Final report

## Scope Pressure Protocol (renforcé — leçons V1)
**STOP IMMÉDIAT** si :
- Test révèle un bug backend (ex. fan-out non implémenté, idempotence ratée, channel format différent) → STOP + documenter comme **nouveau finding** dans le rapport, NE PAS modifier le backend
- Besoin de modifier `phpunit.xml`, `app/`, `database/`, `routes/`, `config/` → SCOPE_PRESSURE
- Besoin d'ajouter une factory ou seeder hors `tests/Feature/Admin/` → SCOPE_PRESSURE
- Besoin de helper trait/abstract test class commune → SCOPE_PRESSURE (pour V1 = inline dans le fichier)
- **Anti-pattern** : `git checkout` ou bypass lockfile → STOP + escalade
- Si Test 4 (`branch_id=null` fan-out) ne matche pas le comportement réel : documenter comme finding, **adapter le test pour matcher le comportement actuel** (test décrit la réalité, pas un wishful design)

## Remediation
- Attempt 1 KO (test rouge sur API ≠ assertion) → diagnostic + replan + Composer re-EXECUTE (ajuster assertion pour matcher réalité)
- Attempt 2 KO (problème de format DB/event/channel) → analyse plus profonde
- Attempt 3 même `bug_signature` → STOP + escalade humaine

## Deliverables
- Diff `tests/Feature/Admin/AvailabilityControllerTest.php` (≤ 200 lignes ajoutées)
- `reports/execution/RUN_P11c_AVAILABILITY_TEST_BIDIRECTIONAL_2026-04-20.md`

## Communication
Subagent renvoie : verdict global, sortie phpunit complète (6/6 attendu), nombre de findings nouveaux découverts, confirmation absence touche backend, output `git status --short`.
