# RUN T09b — Broadcast refactor (BroadcastManager → standard Laravel API)

**Date**: 2026-04-20  
**Cycle**: KIOSK_PHASE_9_5_2026-04-18 — extension scope T09b autorisée par planner-orchestrator (correction P1 broadcast contract, clos audit T09 FAIL).  
**Verdict**: **PASS** ✅  
**Stratégie retenue**: **A** (remplacement minimal `getPusher()->trigger()` → `broadcast()`).

## Contexte

L'audit T09 (REPORT_TASK09_EVENTCONTRACT_OUTBOX_BROADCAST_2026-04-20.md) a identifié que `app/Jobs/DispatchDomainEventsJob.php` utilisait l'API Pusher SDK directement (`$connection->getPusher()->trigger($channels, $event, $payload)`) au lieu de l'API broadcast standard Laravel (`$connection->broadcast($channels, $event, $payload)`).

Conséquence : le code couplait le job à l'implémentation Pusher concrète, empêchant le swap vers `BROADCAST_DRIVER=log` en test (déjà ajouté par T16b dans `phpunit.xml`) et bloquant le portage vers Reverb/Ably.

## Stratégie

**A retenue** :
- `Illuminate\Contracts\Broadcasting\Broadcaster::broadcast(array $channels, string $event, array $payload = []): void` est la signature documentée Laravel 9.
- `PusherBroadcaster::broadcast()` (vendor) appelle `getPusher()->trigger()` en interne → **comportement prod identique**.
- En test avec `BROADCAST_DRIVER=log`, `LogBroadcaster::broadcast()` route l'event vers le canal log `broadcasting`.
- Le no-op `PUSHER_APP_KEY vide → skip` est conservé tel quel (toujours pertinent pour les environnements sans Pusher live).

**B écartée** : créer un événement `DomainEventEnvelope ShouldBroadcastNow` impliquait un refactor des channels (DTO), des contrats EventContract et de la dispatch chain entière. Hors-scope T09b.

## Patches livrés

### 1. `app/Jobs/DispatchDomainEventsJob.php`

```diff
+            // [T09b] Use the standard Laravel Broadcaster API (broadcast)
+            // instead of the Pusher SDK directly (getPusher()->trigger()).
+            // The PusherBroadcaster::broadcast() implementation calls
+            // getPusher()->trigger() internally, so prod behaviour is
+            // unchanged. In test (BROADCAST_DRIVER=log) the LogBroadcaster
+            // routes the event to the `broadcasting` log channel.
-            /** @var \Illuminate\Broadcasting\Broadcasters\PusherBroadcaster $connection */
+            /** @var \Illuminate\Contracts\Broadcasting\Broadcaster $connection */
             $connection = app(BroadcastManager::class)->connection('pusher');
             ...
-                $connection->getPusher()->trigger($channels, $domainEvent->broadcast_as, $envelope);
+                $connection->broadcast($channels, $domainEvent->broadcast_as, $envelope);
```

Le bloc `pusherKey === '' && $isRealManager` (no-op) est inchangé.

### 2. `tests/Feature/OutboxTest.php`

```diff
-        $pusher = Mockery::mock();
-        $pusher->shouldReceive('trigger')->once()->withArgs(...);
-        $connection = Mockery::mock();
-        $connection->shouldReceive('getPusher')->once()->andReturn($pusher);
+        // [T09b] Mock the Laravel Broadcaster::broadcast() API directly.
+        $connection = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
+        $connection->shouldReceive('broadcast')->once()->withArgs(...);
```

### 3. `tests/Feature/EventContractTest.php`

Deux sites de mock adaptés :
- `test_dispatch_job_broadcasts_canonical_envelope` → `Broadcaster::broadcast()` mocké, signature `(array $channels, string $event, array $payload)` préservée.
- `test_dispatch_job_rejects_envelope_that_violates_contract` → `shouldNotReceive('broadcast')` au lieu de `shouldNotReceive('trigger')`.

L'assertion sémantique sur le payload (version, type, aggregate_id, branch_id, occurred_at, correlation_id, payload — clés exactes ordonnées) est **inchangée** : c'est la valeur ajoutée du test.

## Tests — `phpunit --filter "OutboxTest|EventContractTest|CorrelationIdMiddleware"`

```
PHPUnit 9.6.29 by Sebastian Bergmann and contributors.
................                                                  16 / 16 (100%)
Time: 00:01.316, Memory: 71.00 MB
OK (16 tests, 46 assertions)
```

- `OutboxTest` : 5/5 PASS
- `EventContractTest` : 6/6 PASS (2 sites broadcast mockés)
- `CorrelationIdMiddleware*` : 5/5 PASS (non touchés, vérification non-régression T16b)
- Total : **16/16 PASS, 0 fail, 0 skip, 46 assertions**

## Impact

| Aspect | Avant T09b | Après T09b |
|--------|------------|------------|
| API utilisée | Pusher SDK direct (`Pusher\Pusher::trigger`) | Laravel Broadcaster contract (`Broadcaster::broadcast`) |
| Swap driver via `BROADCAST_DRIVER=log` | ❌ Cassé (job hardcode Pusher) | ✅ Fonctionnel (LogBroadcaster utilisé) |
| Swap vers Reverb/Ably | ❌ Refacto requis | ✅ Driver-agnostic |
| Comportement prod | Pusher trigger | Pusher trigger (PusherBroadcaster appelle trigger en interne) |
| Tests mocks | `getPusher()->trigger()` | `broadcast()` direct |
| No-op `PUSHER_APP_KEY` vide | ✅ | ✅ (préservé) |
| AX12-02 `correlation_id` | ✅ T16b | ✅ inchangé |

## Verdict

**PASS** — T09 FAIL → CLOS. Le job utilise l'API broadcast standard Laravel, les tests mockent le contrat `Broadcaster` au lieu du SDK Pusher. Aucune régression sur les 16 tests du périmètre, ni sur AX12-02 (T16b conservé).

## Suivi

- AX12-01 (correlation propagée dans envelope) : déjà couvert par `EventContractTest::test_dispatch_job_broadcasts_canonical_envelope` (assert sur `data['correlation_id']`).
- AX12-02 (listeners corrélation Log::sharedContext) : T16b — non touché.
- Backlog T09 : aucun reste bloquant.
