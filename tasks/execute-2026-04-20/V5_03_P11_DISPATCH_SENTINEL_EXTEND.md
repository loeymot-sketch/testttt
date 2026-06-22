# EXECUTE V5 #3 — P11_DISPATCH_SENTINEL_EXTEND

TASK_ID: P11_DISPATCH_SENTINEL_EXTEND
WAVE: V5 salve L (extension test sentinelle, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: suite directe V4 #8 (`reports/execution/RUN_P11_DISPATCH_AFTER_COMMIT_AUDIT_2026-04-20.md`)

---

## Goal

Étendre le test sentinelle `tests/Feature/DispatchAfterCommitTest.php` (créé en V4 #8) pour couvrir **tous les events broadcast critiques**, pas uniquement `OrderCreated`. Utiliser un **PHPUnit data provider** pour avoir une seule méthode de test paramétrée → 1 méthode, N events, sortie groupée lisible.

---

## Scope

| Fichier | Action |
|---|---|
| `tests/Feature/DispatchAfterCommitTest.php` | EDIT — refactor en data provider, ajouter événements |

**SUBSYSTEMS_TOUCHED**: tests Feature uniquement.
**SUBSYSTEMS_OFF_LIMITS**: app/, routes/, services/, events/. Aucune ligne d'app touchée.
**INVARIANTS_AT_RISK**: aucun (test only).

---

## Spécification

### Liste des events à inclure dans le data provider

Events broadcast cross-surface (temps réel KDS/OSS/Kiosk) :
1. `App\Events\OrderCreated` (déjà testé V4 #8)
2. `App\Events\OrderStatusChanged`
3. `App\Events\ItemAvailabilityChanged`

**Exclure** :
- `Send*` events (notifications queue async — hors scope de l'invariant broadcast)
- `Item/CategoryCreated/Updated/Deleted` — sauf si l'analyse confirme qu'ils sont broadcast cross-surface (sinon hors scope, à confirmer en lisant la classe)

### Pattern data provider

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispatchAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    public static function broadcastEventsProvider(): array
    {
        return [
            'OrderCreated'             => [\App\Events\OrderCreated::class,            \App\Models\Order::class],
            'OrderStatusChanged'       => [\App\Events\OrderStatusChanged::class,      \App\Models\Order::class],
            'ItemAvailabilityChanged'  => [\App\Events\ItemAvailabilityChanged::class, \App\Models\Item::class],
        ];
    }

    /**
     * @dataProvider broadcastEventsProvider
     * @group dispatch_after_commit_invariant
     */
    public function test_event_is_not_dispatched_if_transaction_rolls_back(string $eventClass, string $modelClass): void
    {
        Event::fake([$eventClass]);

        try {
            DB::transaction(function () use ($eventClass, $modelClass) {
                $fakeModel = (new $modelClass())->fill(['id' => 999999]);
                $eventClass::dispatch($fakeModel);
                throw new \RuntimeException('forced rollback');
            });
        } catch (\RuntimeException $e) {
            // attendu
        }

        Event::assertNotDispatched($eventClass, sprintf(
            '[%s] event was dispatched despite transaction rollback. ' .
            'The class must implement ShouldDispatchAfterCommit OR all callers ' .
            'must use ->dispatchAfterCommit(). See V5 #1 P11_DISPATCH_AFTER_COMMIT_REMEDIATION.',
            $eventClass
        ));
    }

    /**
     * @dataProvider broadcastEventsProvider
     * @group dispatch_after_commit_invariant
     */
    public function test_event_is_dispatched_after_successful_commit(string $eventClass, string $modelClass): void
    {
        Event::fake([$eventClass]);

        DB::transaction(function () use ($eventClass, $modelClass) {
            $fakeModel = (new $modelClass())->fill(['id' => 999998]);
            $eventClass::dispatch($fakeModel);
        });

        Event::assertDispatched($eventClass);
    }
}
```

### Avant écriture

1. **Lire** `app/Events/OrderStatusChanged.php` et `app/Events/ItemAvailabilityChanged.php` pour vérifier :
   - Quel modèle ils acceptent dans le constructeur (`Order` / `Item` / autre).
   - Si l'un d'eux a un constructeur qui exige plus qu'un model `fill()`-able, **adapter** le data provider et le test (passer un tableau de constructeur args, pas juste la classe model).
2. Si un event a un constructeur exigeant (ex : `new ItemAvailabilityChanged($item, $isAvailable)` — 2 args), changer le data provider pour passer une closure factory :
   ```php
   'ItemAvailabilityChanged' => [\App\Events\ItemAvailabilityChanged::class, fn() => new \App\Events\ItemAvailabilityChanged((new \App\Models\Item())->fill(['id'=>1]), false)],
   ```
   Et adapter `$eventClass::dispatch($model)` → `$eventClass::dispatch(...$factory()->getDispatchArgs())` ou approche équivalente.

### Tag `@group dispatch_after_commit_invariant`

Le tag PHPUnit permet à l'équipe d'**exclure** ce groupe de la CI principale temporairement avec `--exclude-group dispatch_after_commit_invariant` si besoin de débloquer la pipeline. Ne pas l'exclure d'office — c'est l'utilisateur qui décide.

---

## VALIDATE

1. `vendor/bin/phpunit --filter DispatchAfterCommitTest --testdox`
   - Test `test_event_is_dispatched_after_successful_commit` : **3/3 vert** (positif marche pour tous events)
   - Test `test_event_is_not_dispatched_if_transaction_rolls_back` : 0-3/3 vert selon état de remédiation. Tant que V5 #1 n'est pas appliqué, **3/3 ROUGE attendu** (sentinelles confirment l'étendue du bug). Si l'un est vert, **bonus** = un event a déjà la bonne implémentation.
2. `bash scripts/check-invariants.sh` → reste 6/6 OK (l'invariant 4/6 va devenir rouge SEULEMENT après V5 #2, en parallèle de ce cycle).
3. `git diff tests/Feature/DispatchAfterCommitTest.php` → 1 fichier, refactor complet (suppression 2 méthodes initiales, ajout 2 méthodes paramétrées + provider). Net delta selon style : +60/-30 environ.

---

## REPORT_FILE

`reports/execution/RUN_P11_DISPATCH_SENTINEL_EXTEND_2026-04-20.md` — sortie phpunit testdox + diff complet du fichier test + table récapitulative (par event : positif vert/rouge, négatif vert/rouge).

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier `app/Events/*.php` même si test rouge
- ❌ NE PAS modifier les services (OrderService, etc.)
- ❌ NE PAS étendre à `Send*` events (queue notifications, hors scope broadcast)
- ❌ NE PAS étendre à `Item/CategoryCreated/Updated/Deleted` sauf si l'analyse de la classe confirme qu'ils sont broadcast cross-surface (lire la classe, vérifier `implements ShouldBroadcast`)
- ❌ Pas de `git add/commit`
- ⚠️ Si un event a un constructeur incompatible avec le pattern simple `new $modelClass()`, adapter le data provider pour utiliser une factory closure plutôt que de bricoler ou de skip cet event sans notice
- ⚠️ Si un test 1 est ROUGE pour un event, c'est un signal valide — **ne pas le marquer Incomplete/Skipped**, le tag `@group dispatch_after_commit_invariant` suffit pour exclusion volontaire ultérieure
