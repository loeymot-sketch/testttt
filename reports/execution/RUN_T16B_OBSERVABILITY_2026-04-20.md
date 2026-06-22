# RUN T16b — Observability — 2026-04-20

## Diff appliqué résumé

- `app/Console/Kernel.php`
  - ajout de `use App\Jobs\Observability\SloEvaluatorJob;`
  - ajout du schedule SLO :
    - `$schedule->job(new SloEvaluatorJob)->everyFiveMinutes()->withoutOverlapping(5)->onOneServer();`
  - les schedules existants `purge-expired-otps`, `foodking:outbox:rescue` et `CleanupStalePendingKioskOrders` sont laissés intacts

- `app/Listeners/PersistOrderCreatedToOutbox.php`
  - remplacement de `Str::uuid()` direct par `resolveCorrelationId()`
  - ordre de fallback implémenté :
    1. `Log::sharedContext()['correlation_id']` si string non vide
    2. `request()?->header('X-Correlation-ID')` si string non vide
    3. `Str::uuid()` en dernier recours

- `app/Listeners/PersistOrderStatusChangedToOutbox.php`
  - même correction de résolution de `correlation_id`

- `app/Listeners/PersistItemAvailabilityChangedToOutbox.php`
  - même correction de résolution de `correlation_id`

- `phpunit.xml`
  - ajout de `<env name="BROADCAST_DRIVER" value="log"/>` dans `<php>`
  - aucun changement sur les variables `PUSHER_*`

## Vérifications invariants sensibles

- Pricing SSOT backend : non touché
- `OrderStatus` enum : non touché
- `branch_id` isolation : non touchée
- Dispatch après commit DB : inchangé, `DB::afterCommit()` conservé dans les 3 listeners
- Symmetry `OrderService` / `FrontendOrderService` : N/A, non touchés

## PHPUnit filtré

Commande exécutée :

```bash
./vendor/bin/phpunit --filter "OutboxTest|EventContractTest|CorrelationIdMiddlewareTest"
```

Sortie :

```text
PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

................                                                  16 / 16 (100%)

Time: 00:01.258, Memory: 71.00 MB

OK (16 tests, 46 assertions)
```

## Verdict local

**PASS**
