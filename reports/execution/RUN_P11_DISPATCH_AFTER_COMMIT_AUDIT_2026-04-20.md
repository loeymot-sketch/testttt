# RUN — P11_DISPATCH_AFTER_COMMIT_AUDIT (2026-04-20)

**Statut :** `BUG_FOUND_INVARIANT_BROKEN` (test sentinelle rouge = signal valide ; invariant runtime non satisfait pour `OrderCreated::dispatch()` dans une transaction qui rollback)

---

## Analyse — classe `App\Events\OrderCreated`

| Question | Réponse |
|----------|---------|
| Implémente `Illuminate\Contracts\Broadcasting\ShouldBroadcast` ? | **Non** |
| Implémente `Illuminate\Contracts\Events\ShouldDispatchAfterCommit` ? | **Non** |
| Implémentation actuelle | Classe « plain » avec uniquement le trait `Illuminate\Foundation\Events\Dispatchable` ; constructeur typé `BroadcastableOrder $order`. |

**Conclusion :** La responsabilité « after commit » n’est pas portée par l’event via `ShouldDispatchAfterCommit`. Le test runtime montre que `OrderCreated::dispatch()` à l’intérieur d’une `DB::transaction` est **observable avant commit** ; en cas de rollback, l’event reste quand même enregistré par `Event::fake` → **comportement incompatible** avec l’invariant « dispatch only after successful commit » au sens strict Laravel pour cet appel.

**Recommandation (cycle GPT-5 / implémentation ultérieure — hors scope EXECUTE actuel) :**

- Ajouter `implements \Illuminate\Contracts\Events\ShouldDispatchAfterCommit` sur `OrderCreated`, **ou**
- Refactorer les callers pour utiliser explicitement `OrderCreated::dispatch($order)->dispatchAfterCommit()` (ou équivalent) là où l’ordre est créé dans une transaction.

**Ne pas appliquer ici** : le plan V4_08 interdisait toute modification applicative.

---

## Sortie PHPUnit complète

```
PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

F.                                                                  2 / 2 (100%)

Time: 00:00.169, Memory: 44.50 MB

There was 1 failure:

1) Tests\Feature\DispatchAfterCommitTest::test_order_created_event_is_not_dispatched_if_transaction_rolls_back
The unexpected [App\Events\OrderCreated] event was dispatched.
Failed asserting that actual size 1 matches expected size 0.

/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Testing/Fakes/EventFake.php:177
/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php:338
/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/DispatchAfterCommitTest.php:28

FAILURES!
Tests: 2, Assertions: 2, Failures: 1.
```

- `test_order_created_event_is_not_dispatched_if_transaction_rolls_back` : **échec** (event dispatché malgré rollback).
- `test_order_created_event_is_dispatched_after_successful_commit` : **succès**.

---

## Fichier créé — `tests/Feature/DispatchAfterCommitTest.php`

Fichier non tracké ; « diff » = contenu ajouté intégral (nouveau fichier).

```php
<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DispatchAfterCommitTest extends TestCase
{
    public function test_order_created_event_is_not_dispatched_if_transaction_rolls_back(): void
    {
        Event::fake([OrderCreated::class]);

        try {
            DB::transaction(function () {
                $fakeOrder = (new Order())->fill(['id' => 999999]);
                OrderCreated::dispatch($fakeOrder);

                throw new \RuntimeException('forced rollback');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        Event::assertNotDispatched(OrderCreated::class);
    }

    public function test_order_created_event_is_dispatched_after_successful_commit(): void
    {
        Event::fake([OrderCreated::class]);

        DB::transaction(function () {
            $fakeOrder = (new Order())->fill(['id' => 999998]);
            OrderCreated::dispatch($fakeOrder);
        });

        Event::assertDispatched(OrderCreated::class);
    }
}
```

---

## `BUG_FOUND_INVARIANT_BROKEN`

Le grep statique `[4/6] App\Events\* dispatch afterCommit` peut passer alors que le dispatch runtime de `OrderCreated` dans une transaction annulée **n’est pas** différé jusqu’après commit. Le test sentinelle le démontre : `Event::assertNotDispatched` échoue après rollback forcé.

---

## `scripts/check-invariants.sh`

**Résultat : 6/6 OK** (inchangé ; l’audit runtime complète le grep sans le casser).

```
== POS invariants CI guard (POS_INVARIANTS_AND_GATES.md §3) ==
  [1/6 SSOT pricing (no payload pricing)] ... OK
  [2/6 branch_id server-side only] ... OK
  [3/6 status via OrderStateMachine] ... OK
  [4/6 App\Events\* dispatch afterCommit] ... OK
  [5/6 EventContract envelope] ... OK
  [6/6 audit log on sensitive actions] ... OK

==> All 6 POS invariants clean.
```

---

## Git (attendu par le plan)

- `?? tests/Feature/DispatchAfterCommitTest.php`
- `?? reports/execution/RUN_P11_DISPATCH_AFTER_COMMIT_AUDIT_2026-04-20.md`

Pas de `git add` / `commit` exécuté.

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — BUG_FOUND_INVARIANT_BROKEN — 0 remediation app code (volontaire)**

| # | Check | Résultat |
|---|---|---|
| 1 | Re-run `vendor/bin/phpunit --filter DispatchAfterCommitTest` | 1 PASS (commit) + 1 FAIL ATTENDU (rollback) |
| 2 | Analyse `app/Events/OrderCreated.php` | confirmé : `class OrderCreated { use Dispatchable; }` — **NI** `ShouldDispatchAfterCommit` **NI** `ShouldBroadcast` |
| 3 | Usage dans services | `OrderService.php:541, 961, 1266` + `FrontendOrderService.php:842` utilisent `OrderCreated::dispatch($order)` simple, **pas** `dispatchAfterCommit()` |
| 4 | Pourquoi check-invariants.sh 4/6 passe quand même ? | grep statique cherche `App\\Events\\X::dispatch(`. Dans `FrontendOrderService.php`, `use App\Events\OrderCreated` permet d'écrire `OrderCreated::dispatch(...)` sans préfixe → **faux négatif du grep**. Dans `OrderService.php`, le code utilise `\App\Events\OrderCreated::dispatch(...)` (préfixe absolu) qui **devrait matcher** — à investiguer (peut-être `// allow:` au-dessus, ou variante de regex). |
| 5 | Test sentinelle CI | Le test 1 reste **rouge volontairement** comme alarme — pattern Laravel standard "test invariant broken" |

**Valeur produite** : **bug de production identifié**. Si une transaction de création d'order rollback APRÈS l'appel `OrderCreated::dispatch()` (exemple : exception dans une étape post-event au sein de la même transaction), le KDS / OSS / Kiosk reçoivent un broadcast pour un order **fantôme** qui n'existe pas en DB → désynchro cross-surface, audit log incohérent.

**Action de suite obligatoire** : cycle de remédiation GPT-5.4 + GATE (frozen zone OrderService) — voir nouveau plan `tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md`.

**Statut CI** : test 1 rouge persistant. **Choix orchestrateur** : ne PAS skip le test, ne PAS le marquer Incomplete — il EST l'alarme rouge légitime jusqu'à remédiation. Si la CI doit absolument être verte avant la remédiation, l'utilisateur peut wrapper le test 1 dans `@group dispatch_invariant_broken` et exclure ce groupe de la suite par défaut (1 ligne de phpunit.xml).
