# Codex — VA-SYS-08 Realtime / Outbox Production-Like Audit — 2026-04-30

TASK_ID: `CENTRAL-SYNC-VA-SYS-FINISHING/VA-SYS-08`

## Verdict

`VA_SYS_08_VERDICT: PASS_RUNTIME_LOCAL_STRONG`

`RELEASE_SCOPE_DECISION: SOFTWARE_SYNC_LOCAL_PASS__HARDWARE_PROVIDER_UAT_DEFERRED`

Objectif: prouver que la couche synchronisation centrale FoodKing tient localement sur les axes outbox, contrats d'events, reprise apres panne broadcaster, fanout branch-scoped, fallback/reconnect front, et runtime multi-surface Kiosk/POS/KDS/OSS sans reload manuel.

## Changement livre

Nouveau test:

- `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`

Ce test ne modifie pas le runtime produit. Il ajoute une simulation de production pour les cas qui etaient jusque-la surtout couverts par tests unitaires ou contrats separes:

- rescue outbox: ne requeue que les events pending, stale, retriables, attempts < 5
- retry-failed: ne reset que les events terminal failed recents dans la fenetre demandee
- panne broadcaster: le claim est relache, `attempts` et `last_error` sont traces, rescue peut requeue, puis un retry sain dispatch correctement
- fanout global catalogue: `CategoryUpdated` produit un `CatalogChanged` par branche active, meme correlation, channel `private-branch.{branch_id}`, enveloppe valide
- violation contractuelle: payload invalide ne touche jamais le broadcaster, reste retriable, et garde `last_error=contract_violation:*`

Artifacts mis a jour:

- `missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md`
- `reports/antigravity/c3-runtime-multi-surface.json`

## Pre-audit raisonne

Risques verifies avant validation:

1. Outbox duplication ou perte: un event ne doit pas etre marque dispatched si le provider tombe.
2. Retry dangereux: une commande stale ou failed doit pouvoir revenir, mais pas un event frais, deja dispatched, ou maxed-out.
3. Cross-branch sync leak: un refresh global catalogue doit cibler les branches actives sans envoyer sur un canal commun non scope.
4. Contrat event: un payload invalide doit bloquer avant broadcast, sinon KDS/OSS/POS peuvent consommer un message incoherent.
5. Runtime multi-surface: les preuves statiques ne suffisent pas; C3 doit prouver un DOM update KDS/OSS/POS depuis une creation Kiosk/POS locale.

## Validations executees

### Outbox / rescue / retry

`php -l tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`

- PASS

`php artisan test tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`

- PASS: 5 tests

Run-many:

`for i in 1 2 3; do php artisan test tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php; done`

- PASS 3/3
- Total boucle: 15 assertions de scenario testees via 3 runs complets de la suite, sans retry magique

Suites proches:

- `php artisan test tests/Feature/Outbox` -> PASS: 14 tests
- `php artisan test tests/Feature/OutboxRescueTest.php` -> PASS: 2 tests
- `php artisan test tests/Feature/OutboxTest.php` -> PASS: 6 tests

### Catalog / menu / projection sync

- `php artisan test tests/Feature/Catalog/CatalogChangedDispatchTest.php` -> PASS: 2 tests
- `php artisan test tests/Feature/Catalog/CatalogOutboxIdempotencyTest.php` -> PASS: 1 test
- `php artisan test tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php` -> PASS: 3 tests
- `php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php` -> PASS: 1 test

### Event contract / after commit

- `php artisan test tests/Feature/EventContractTest.php` -> PASS: 9 tests
- `php artisan test tests/Unit/Domain/Events/EventContractUnitTest.php` -> PASS: 12 tests
- `php artisan test tests/Feature/AfterCommitDispatchTest.php` -> PASS: 14 tests
- `php artisan test tests/Feature/DispatchAfterCommitTest.php` -> PASS: 8 tests
- `php artisan test tests/Feature/KioskRealtimeBroadcastTest.php` -> PASS: 2 tests

### Sync backend / JS realtime contracts

- `php artisan test tests/Feature/SyncComprehensiveTest.php` -> PASS: 6 tests

`npx vitest run tests/js/eventContractDedupe.spec.js tests/js/correlationDedupePersistence.spec.js tests/js/correlationDedupeCapacity.spec.js tests/js/realtimeBroadcastFallback.spec.js tests/js/kdsReactsToReconnectStorm.spec.js tests/js/kdsBackoffOn5xx.spec.js tests/js/kdsSyncCadence.spec.js tests/js/kdsVersionGate.spec.js`

- PASS: 8 files
- PASS: 29 tests
- Note: warnings console attendus sur enveloppes invalides dans les tests de validation negative.

### Runtime multi-surface C3

Serveur local temporaire: `php artisan serve --host=127.0.0.1 --port=8000`.

`npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=1 --retries=0`

- PASS: 2 tests

`npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=2 --retries=0`

- PASS: 4 tests

Dernier artifact JSON:

- `reports/antigravity/c3-runtime-multi-surface.json`
- `kiosk_cash_to_kds_pos_oss`: KDS 5878 ms, OSS preparing 2884 ms, order_id 663
- `pos_to_kds_oss`: KDS 5881 ms, OSS preparing 3876 ms, order_id 664

Le test C3 valide un changement DOM visible cote KDS/OSS/POS depuis creation Kiosk/POS locale, sans reload manuel, sur la limite locale de l'environnement actuel.

### Hygiene

- `git diff --check -- tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md reports/post_execute_latest.log` -> PASS

## Invariants FoodKing verifies

- Pricing SSOT backend: aucun calcul prix frontend ajoute ou modifie.
- `OrderStatus`: aucune chaine magique ajoutee.
- `branch_id`: fanout catalogue valide uniquement vers `private-branch.{branch_id}` pour branches actives; pas de canal global non scope.
- Dispatch after commit: les suites after-commit existantes restent vertes.
- Frozen zones: pas d'edition `OrderService.php` / `FrontendOrderService.php`.
- Outbox idempotence/reprise: panne provider ne produit pas de faux `dispatched_at`; la reprise rescue est bornee.

## Ce qui est valide fortement maintenant

1. L'outbox ne marque pas un event comme dispatch si le broadcaster echoue.
2. Les events failed/pending ne sont pas relances sans filtre; stale/recent/attempts/dispatched_at sont respectes.
3. Un `CategoryUpdated` global se transforme en events branch-scoped actifs avec enveloppes valides.
4. Une violation de contrat event stoppe avant broadcast et laisse une trace exploitable.
5. Les contrats JS de dedupe, persistence correlation, fallback broadcast, backoff, cadence et version gate passent.
6. Le runtime local C3 prouve Kiosk/POS -> KDS/OSS/POS visible sans reload manuel.

## Limites honnetes

- Ce n'est pas un test provider cloud reel Pusher/Reverb en production: l'UAT hardware/provider reste separe.
- SQLite/local ne remplace pas un stress MySQL/Redis prod-like pour tous les locks; les suites existantes couvrent deja une partie forte, mais staging MySQL/Redis reste le bon prochain cran avant go-live commercial.
- Les timings C3 locaux depassent 5s pour KDS dans le dernier JSON (~5.88s). Le verdict reste PASS local parce que le DOM update arrive sans reload et les suites C3 repetent vert, mais le budget perf cible doit rester surveille en staging.

## Decision

`VA-SYS-08: CLOSED_LOCAL_PASS`

La prochaine etape logique est `VA-SYS-09`:

- consolider docs/runbook/memory pour centralisation, outbox, refresh catalogue, rupture stock, branch isolation
- ecrire clairement les limites hardware/provider laissees a l'UAT
- preparer `VA-SYS-10` final massive validation avec relance selective des suites critiques
