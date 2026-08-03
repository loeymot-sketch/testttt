# RUN — P11_DISPATCH_SENTINEL_EXTEND (V5 #3)

**Date:** 2026-04-20  
**Fichier test:** `tests/Feature/DispatchAfterCommitTest.php`  
**SUBSYSTEMS_TOUCHED:** tests Feature uniquement (aucun changement sous `app/`).

---

## Analyse constructeurs (lecture obligatoire)

| Event | Constructeur | Adaptation data provider |
| --- | --- | --- |
| `OrderCreated` | `BroadcastableOrder $order` | Args: `[(new Order())->fill(['id' => 999999])]` |
| `OrderStatusChanged` | `BroadcastableOrder $order, int $oldStatus, int $newStatus` | Args: `[(new Order())->fill(['id' => 999999]), 1, 2]` |
| `ItemAvailabilityChanged` | `int $itemId, int $status, float $price, ...` (pas de modèle seul) | Args: `[999999, 1, 9.99]` (dispatch positionnel) |

Factory closure unique par ligne : `callable (): array` dont le spread alimente `$eventClass::dispatch(...)`.

---

## PHPUnit — sortie complète (`vendor/bin/phpunit --filter DispatchAfterCommitTest --testdox`)

```
PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

Dispatch After Commit (Tests\Feature\DispatchAfterCommit)
 ✘ Event is not dispatched if transaction rolls back with data set "OrderCreated"
   │
   │ The unexpected [App\Events\OrderCreated] event was dispatched.
   │ Failed asserting that actual size 1 matches expected size 0.
   │
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Testing/Fakes/EventFake.php:177
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php:338
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/DispatchAfterCommitTest.php:58
   │

 ✘ Event is not dispatched if transaction rolls back with data set "OrderStatusChanged"
   │
   │ The unexpected [App\Events\OrderStatusChanged] event was dispatched.
   │ Failed asserting that actual size 1 matches expected size 0.
   │
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Testing/Fakes/EventFake.php:177
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php:338
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/DispatchAfterCommitTest.php:58
   │

 ✘ Event is not dispatched if transaction rolls back with data set "ItemAvailabilityChanged"
   │
   │ The unexpected [App\Events\ItemAvailabilityChanged] event was dispatched.
   │ Failed asserting that actual size 1 matches expected size 0.
   │
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Testing/Fakes/EventFake.php:177
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php:338
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/DispatchAfterCommitTest.php:58
   │

 ✔ Event is dispatched after successful commit with data set "OrderCreated"
 ✔ Event is dispatched after successful commit with data set "OrderStatusChanged"
 ✔ Event is dispatched after successful commit with data set "ItemAvailabilityChanged"

Time: 00:00.651, Memory: 65.00 MB

Summary of non-successful tests:

Dispatch After Commit (Tests\Feature\DispatchAfterCommit)
 ✘ Event is not dispatched if transaction rolls back with data set "OrderCreated"
   │
   │ The unexpected [App\Events\OrderCreated] event was dispatched.
   │ Failed asserting that actual size 1 matches expected size 0.
   │
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Testing/Fakes/EventFake.php:177
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php:338
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/DispatchAfterCommitTest.php:58
   │

 ✘ Event is not dispatched if transaction rolls back with data set "OrderStatusChanged"
   │
   │ The unexpected [App\Events\OrderStatusChanged] event was dispatched.
   │ Failed asserting that actual size 1 matches expected size 0.
   │
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Testing/Fakes/EventFake.php:177
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php:338
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/DispatchAfterCommitTest.php:58
   │

 ✘ Event is not dispatched if transaction rolls back with data set "ItemAvailabilityChanged"
   │
   │ The unexpected [App\Events\ItemAvailabilityChanged] event was dispatched.
   │ Failed asserting that actual size 1 matches expected size 0.
   │
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Testing/Fakes/EventFake.php:177
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php:338
   │ /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/DispatchAfterCommitTest.php:58
   │

FAILURES!
Tests: 6, Assertions: 6, Failures: 3.
```

---

## Tableau récapitulatif (sentinelles)

| Event | Test positif (commit) | Test négatif (rollback) | Interprétation rollback |
| --- | --- | --- | --- |
| `OrderCreated` | vert | rouge | `BUG_FOUND_INVARIANT_BROKEN` attendu — pas de patch `app/`. |
| `OrderStatusChanged` | vert | rouge | Idem. |
| `ItemAvailabilityChanged` | vert | rouge | Idem. |

---

## `scripts/check-invariants.sh`

Exécution après les changements (les tests ne modifient pas ce script ; l’état reflète le dépôt, pas cette PR test-only) :

```
== POS invariants CI guard (POS_INVARIANTS_AND_GATES.md §3) ==
  [1/6 SSOT pricing (no payload pricing)] ... OK
  [2/6 branch_id server-side only] ... OK
  [3/6 status via OrderStateMachine] ... OK
  [4/6 App\Events\* dispatch afterCommit] ... FAIL (8 hit(s))
  [5/6 EventContract envelope] ... OK
  [6/6 audit log on sensitive actions] ... OK

==> 1 invariant(s) violated (8 total hit(s)).
```

---

## Écart par rapport au snippet du plan (`assertNotDispatched`)

Le plan proposait un 2e argument `sprintf(...)` sur `Event::assertNotDispatched`. Sous Laravel 9, la signature est `assertNotDispatched($event, $callback = null)` : le 2e paramètre est un **callable filtre**, pas un message. Passer une chaîne provoquerait une erreur si des dispatchs existent. Implémentation retenue : `Event::assertNotDispatched($eventClass)` + commentaire dans le test.

---

## Diff complet du fichier test (avant → après)

Référence « avant » : version V4 #8 telle que présente en atelier (deux méthodes dédiées `OrderCreated` uniquement). « Après » : fichier actuel dans le dépôt.

```diff
--- a/DispatchAfterCommitTest.before.php
+++ b/tests/Feature/DispatchAfterCommitTest.php
@@ -2,41 +2,75 @@
 
 namespace Tests\Feature;
 
+use App\Events\ItemAvailabilityChanged;
 use App\Events\OrderCreated;
+use App\Events\OrderStatusChanged;
 use App\Models\Order;
+use Illuminate\Foundation\Testing\RefreshDatabase;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Event;
 use Tests\TestCase;
 
 class DispatchAfterCommitTest extends TestCase
 {
-    public function test_order_created_event_is_not_dispatched_if_transaction_rolls_back(): void
+    use RefreshDatabase;
+
+    /**
+     * @return array<string, array{0: class-string, 1: callable(): array<int, mixed>}>
+     */
+    public static function broadcastEventsProvider(): array
     {
-        Event::fake([OrderCreated::class]);
+        return [
+            'OrderCreated' => [
+                OrderCreated::class,
+                static fn (): array => [(new Order())->fill(['id' => 999999])],
+            ],
+            'OrderStatusChanged' => [
+                OrderStatusChanged::class,
+                static fn (): array => [(new Order())->fill(['id' => 999999]), 1, 2],
+            ],
+            'ItemAvailabilityChanged' => [
+                ItemAvailabilityChanged::class,
+                static fn (): array => [999999, 1, 9.99],
+            ],
+        ];
+    }
 
-        try {
-            DB::transaction(function () {
-                $fakeOrder = (new Order())->fill(['id' => 999999]);
-                OrderCreated::dispatch($fakeOrder);
+    /**
+     * @dataProvider broadcastEventsProvider
+     *
+     * @group dispatch_after_commit_invariant
+     */
+    public function test_event_is_not_dispatched_if_transaction_rolls_back(string $eventClass, callable $dispatchArgsFactory): void
+    {
+        Event::fake([$eventClass]);
 
+        try {
+            DB::transaction(function () use ($eventClass, $dispatchArgsFactory) {
+                $eventClass::dispatch(...$dispatchArgsFactory());
                 throw new \RuntimeException('forced rollback');
             });
         } catch (\RuntimeException $e) {
             // expected
         }
 
-        Event::assertNotDispatched(OrderCreated::class);
+        // Laravel Event::assertNotDispatched second parameter is a filter callback, not a custom message.
+        Event::assertNotDispatched($eventClass);
     }
 
-    public function test_order_created_event_is_dispatched_after_successful_commit(): void
+    /**
+     * @dataProvider broadcastEventsProvider
+     *
+     * @group dispatch_after_commit_invariant
+     */
+    public function test_event_is_dispatched_after_successful_commit(string $eventClass, callable $dispatchArgsFactory): void
     {
-        Event::fake([OrderCreated::class]);
+        Event::fake([$eventClass]);
 
-        DB::transaction(function () {
-            $fakeOrder = (new Order())->fill(['id' => 999998]);
-            OrderCreated::dispatch($fakeOrder);
+        DB::transaction(function () use ($eventClass, $dispatchArgsFactory) {
+            $eventClass::dispatch(...$dispatchArgsFactory());
         });
 
-        Event::assertDispatched(OrderCreated::class);
+        Event::assertDispatched($eventClass);
     }
 }
```

---

## Statut final

**SUCCESS** — refactor data provider + 3 events + `@group dispatch_after_commit_invariant` sur les deux méthodes ; les 3 échecs rollback sont le signal sentinelle attendu (`BUG_FOUND_INVARIANT_BROKEN`), sans modification applicative.

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PASSED (refactor) + 3 BUG_FOUND_INVARIANT_BROKEN volontaires**

| # | Check | Résultat |
|---|---|---|
| 1 | Refactor data provider | propre, closure factory pour gérer les constructeurs multi-args (OrderStatusChanged 3 args, ItemAvailabilityChanged 3 scalaires) |
| 2 | Re-run `vendor/bin/phpunit --filter DispatchAfterCommitTest --testdox` | 6 tests : 3 ✔ commit + 3 ✘ rollback (signal valide attendu) |
| 3 | Tag `@group dispatch_after_commit_invariant` | présent sur les 2 méthodes, permet exclusion CI volontaire si besoin |
| 4 | Aucun event ni service modifié | confirmé via `git status` |
| 5 | Test file size | 76 lignes (refactor compact, lisible) |
| 6 | Découverte technique correcte | subagent a identifié `Event::assertNotDispatched` Laravel 9 ne supporte pas message custom en arg 2 — adapté correctement le test |

**Convergence avec V5 #2 (grep statique)** :
- V5 #2 statique : 8 violations sur OrderService + FrontendOrderService, sur OrderCreated ET OrderStatusChanged
- V5 #3 runtime : 3/3 events broadcast (OrderCreated, OrderStatusChanged, ItemAvailabilityChanged) échouent au rollback
- **L'élargissement du scope du bug est désormais documenté** : V5 #1 (P11_DISPATCH_AFTER_COMMIT_REMEDIATION) doit traiter **3 events** au minimum, pas uniquement OrderCreated comme initialement estimé.

**Valeur produite** :
- Couverture sentinelle multipliée par 3 (1 event → 3 events)
- Data provider extensible : ajouter un 4e event = 1 ligne dans le provider
- Le rouge en CI PHPUnit est désormais "structurel" (3 cas paramétrés visibles dans la sortie testdox) — facilite la triage.

**Recommandation orchestrateur** : mettre à jour V5 #1 plan pour couvrir les 3 events identifiés ET l'option d'ajouter `ItemAvailabilityChanged` à la liste des events à traiter (Stratégie A étendue).
