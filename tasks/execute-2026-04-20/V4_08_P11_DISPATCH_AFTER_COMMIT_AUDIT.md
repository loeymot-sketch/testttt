# EXECUTE V4 #8 — P11_DISPATCH_AFTER_COMMIT_AUDIT

TASK_ID: P11_DISPATCH_AFTER_COMMIT_AUDIT
WAVE: V4 salve 4a (test sentinelle, zéro risque applicatif)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE_FINDING: cycles supplémentaires §1.4 PLAN_POST_VERIFY (P11_DISPATCH_AFTER_COMMIT_AUDIT)
RELATED_INVARIANT: `scripts/check-invariants.sh` invariant 4/6 (statique)

---

## Goal

Créer **un test PHPUnit** qui prouve que `App\Events\OrderCreated` (dispatched depuis `OrderService::posOrderStore`) **n'est PAS dispatché si la transaction DB rollback**.

Aujourd'hui, l'invariant `dispatch-after-commit` est uniquement vérifié par grep statique (4/6 dans `check-invariants.sh`). Un grep peut être contourné par n'importe quelle indirection. Un **test runtime** est la preuve forte.

---

## Scope

| Fichier | Action |
|---|---|
| `tests/Feature/DispatchAfterCommitTest.php` | **NEW** — 1 test minimum, peut en avoir 2 (positif + négatif). |

**SUBSYSTEMS_TOUCHED**: tests Feature uniquement.
**SUBSYSTEMS_OFF_LIMITS**: app/, services/, listeners/, routes/, migrations/. Aucun changement applicatif.
**INVARIANTS_AT_RISK**: aucun (test only).

---

## Spécification

### Test 1 — `test_order_created_event_is_not_dispatched_if_transaction_rolls_back`

Pattern :

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use App\Events\OrderCreated;

public function test_order_created_event_is_not_dispatched_if_transaction_rolls_back(): void
{
    Event::fake([OrderCreated::class]);

    try {
        DB::transaction(function () {
            // Simule un dispatch d'event À L'INTÉRIEUR de la transaction.
            // Si l'event utilise ->dispatch() simple, il sera observable AVANT le rollback.
            // Si l'event utilise ->dispatchAfterCommit() (correct), il ne sera observable APRÈS le commit.
            $fakeOrder = (new \App\Models\Order())->fill(['id' => 999999]); // pas persisté
            OrderCreated::dispatch($fakeOrder);

            throw new \RuntimeException('forced rollback');
        });
    } catch (\RuntimeException $e) {
        // attendu
    }

    // Si OrderCreated supporte afterCommit (correct), il NE doit PAS avoir été dispatché.
    Event::assertNotDispatched(OrderCreated::class);
}
```

> **Note importante** : ce test échoue si `OrderCreated` n'implémente PAS `ShouldDispatchAfterCommit` (interface Laravel 10+) OU n'est pas systématiquement dispatché via `->dispatchAfterCommit()`. Si test rouge, on a **prouvé un bug invariant** → ne pas corriger ici, juste documenter `BUG_FOUND_INVARIANT_BROKEN` dans le RUN report.

### Test 2 (optionnel mais recommandé) — `test_order_created_event_is_dispatched_after_successful_commit`

Le miroir positif :

```php
public function test_order_created_event_is_dispatched_after_successful_commit(): void
{
    Event::fake([OrderCreated::class]);

    DB::transaction(function () {
        $fakeOrder = (new \App\Models\Order())->fill(['id' => 999998]);
        OrderCreated::dispatch($fakeOrder);
    });

    Event::assertDispatched(OrderCreated::class);
}
```

### Avant écriture
1. Lire `app/Events/OrderCreated.php` pour voir si la classe implémente `ShouldDispatchAfterCommit` ou si la responsabilité est portée par les services qui dispatchent (`->dispatchAfterCommit()`).
2. Si la classe utilise `ShouldDispatchAfterCommit` → test runtime simple comme ci-dessus.
3. Si non → adapter le test pour cibler `OrderService::posOrderStore` directement (préparer un payload minimal qui rollback via une exception forcée). Si trop coûteux, **se limiter au test 1** sur `OrderCreated::dispatch()` direct ; documenter dans le RUN report l'analyse de pourquoi (qui a la responsabilité afterCommit : event ou caller).

---

## VALIDATE
1. `vendor/bin/phpunit --filter DispatchAfterCommitTest` → idéalement vert ; si rouge → analyse à inclure dans le RUN report comme **BUG_FOUND** (ne pas patcher app code).
2. `bash scripts/check-invariants.sh` → reste OK 6/6.
3. `git status --short` exact : `?? tests/Feature/DispatchAfterCommitTest.php` + `?? reports/execution/RUN_P11_DISPATCH_AFTER_COMMIT_AUDIT_2026-04-20.md`.

---

## REPORT_FILE

`reports/execution/RUN_P11_DISPATCH_AFTER_COMMIT_AUDIT_2026-04-20.md` — sortie phpunit inline + analyse de la classe `OrderCreated` (afterCommit ou non).

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier `app/Events/OrderCreated.php` même si le test rouge — diagnostic only.
- ❌ NE PAS modifier `OrderService` ni `FrontendOrderService`.
- ❌ NE PAS toucher `tests/Feature/OutboxTest.php` existant.
- ❌ Pas de `git add/commit`.
