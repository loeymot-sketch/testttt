# PLAN_AUDIT_F015 — Queue Config Production Blocker
**Severity:** P0 — Production blocker (séparé des F-001..F-014 audit tactique)
**Owner agent:** Agent G (DevOps/Ops)
**Sprint:** S0 (avant Phase 0 stabilize)
**Estimated:** 1 jour-agent
**Frozen-zone override:** NO

---

## 0. STOP CHECKLIST

| # | Q | A |
|---|---|---|
| 1 | Why ? | `.env.example` defaults `QUEUE_CONNECTION=sync` + doc REALTIME_SETUP affirme "sync est suffisant car ShouldBroadcastNow", MAIS le code utilise outbox pattern qui exige queue worker. Deploy avec defaults = realtime mort silencieusement |
| 2 | What ? | (a) Mettre à jour REALTIME_SETUP.md ; (b) modifier `.env.example` defaults ; (c) ajouter health check worker ; (d) monitoring outbox stale ; (e) test feature delivery |
| 3 | Where ? | `docs/REALTIME_SETUP.md`, `.env.example`, `app/Http/Controllers/HealthController.php`, nouveau `app/Console/Commands/MonitorOutboxStaleness.php`, nouveau test |
| 4 | Who ? | Tous les operators qui suivent la doc, KDS, OSS, POS, Kiosk, mobile customer, FCM future |
| 5 | How ? | `tests/Feature/Realtime/OutboxDeliveryTest.php` qui FAIL si dispatched_at NULL après 5s avec worker, et FAIL aussi si sync mode utilisé sans broadcast direct |
| 6 | When rollback ? | Pas de rollback — c'est purement docs + monitoring + healthcheck additif |

---

## 1. THINK

### 1.1 Évidence brute

**`.env.example:71-75`** :
```
# [CRITICAL-PROD] QUEUE_CONNECTION=sync is synchronous and blocks the API.
QUEUE_CONNECTION=sync
# QUEUE_CONNECTION=redis  # [PRODUCTION] Uncomment this line in production
# QUEUE_CONNECTION=database  # Alternative if Redis unavailable
```

**`config/queue.php:16`** :
```php
'default' => env('QUEUE_CONNECTION', 'sync'),
```

**`docs/REALTIME_SETUP.md:88`** :
> `QUEUE_CONNECTION=sync` est suffisant car tous les events broadcast utilisent `ShouldBroadcastNow` (exécution synchrone dans la requête HTTP, pas de worker nécessaire).

**Réalité du code** :

`app/Events/OrderCreated.php:12` :
```
* replacing direct ShouldBroadcastNow dispatch from this event class.
```

→ Les events ne sont PLUS `ShouldBroadcastNow`.

`app/Listeners/PersistOrderCreatedToOutbox.php:36-38` :
```php
DB::afterCommit(function () use ($domainEvent): void {
    DispatchDomainEventsJob::dispatch($domainEvent->id)->onQueue('high');
});
```

→ Le dispatch passe par la queue. Si queue=sync, exécuté immédiatement après commit dans la même requête HTTP. Si queue=database/redis sans worker, JAMAIS dispatché.

### 1.2 Scénarios d'échec en prod

**Scénario A — Operator suit REALTIME_SETUP.md**
1. Met `QUEUE_CONNECTION=sync` en prod (suit la doc).
2. Tous les broadcasts sont dispatchés synchronement dans la requête HTTP.
3. Si Pusher ralentit/timeout, l'API HTTP timeout.
4. Si Pusher crash, toutes les commandes 500.
5. **Pas de durabilité** : si la requête HTTP fail entre le COMMIT et le broadcast, l'event est perdu (déjà mitigé par outbox row mais le broadcast lui-même peut être perdu).

**Scénario B — Operator change pour redis sans démarrer worker**
1. Met `QUEUE_CONNECTION=redis` (suit le commentaire `[PRODUCTION]`).
2. Oublie de démarrer `php artisan queue:work --queue=high`.
3. **DispatchDomainEventsJob s'accumulent dans Redis sans être traités.**
4. KDS, OSS, POS reçoivent **rien en realtime**.
5. Le polling 30s masque cosmétiquement → "OSS lags by 30s always".
6. Découvert seulement quand quelqu'un remarque la latence.

**Scénario C — Operator déploie sans toucher .env**
1. `.env` copié de `.env.example` → `QUEUE_CONNECTION=sync`.
2. Voit le warning `[CRITICAL-PROD]` mais ne sait pas que ça impacte broadcasts.
3. Prod tourne en sync → API timeouts en pic charge.

### 1.3 Pourquoi c'est P0 séparé du tactique

Les F-001..F-014 sont des bugs / améliorations ciblées du code applicatif. F-015 est un **blocker de déploiement**. Aucun des fixes tactiques ne résout le problème opérationnel qu'un nouveau deploy peut être silencieusement cassé.

---

## 2. PLAN

### 2.1 5 actions coordonnées

1. **Doc REALTIME_SETUP.md** : retirer la phrase obsolète, documenter le worker requis.
2. **`.env.example`** : passer `QUEUE_CONNECTION=redis` en commentaire actif et `sync` en commentaire `[DEV ONLY]`.
3. **HealthController::ready** : étendre pour vérifier qu'un worker traite la queue `high` récemment.
4. **Monitor command** : `php artisan foodking:outbox:monitor` qui lance une alerte si > 10 events stale (NULL dispatched_at depuis > 30s).
5. **Test feature** : `tests/Feature/Realtime/OutboxDeliveryTest.php` qui prouve que (a) en sync mode events sont dispatchés OK, (b) en database mode SANS worker, dispatched_at reste NULL — donc le test ECHOUE explicitement et l'erreur est lisible.

---

## 3. BUILD — Sub-tasks

### 3.1 Doc REALTIME_SETUP.md correction (1h)

**File:** `docs/REALTIME_SETUP.md`

**REPLACE section "Queue" (lignes ~86-96):**

```markdown
## Queue (post outbox refactor)

⚠️ **AVERTISSEMENT** : Cette section a été corrigée le 2026-05-07.

Depuis le refactor outbox pattern (`app/Events/OrderCreated.php`, `app/Listeners/PersistOrderCreatedToOutbox.php`), les events broadcast ne sont **PLUS** `ShouldBroadcastNow`. Ils sont persistés en `domain_events` puis dispatchés par `DispatchDomainEventsJob` via la queue.

**EN PRODUCTION** : `QUEUE_CONNECTION=sync` n'est **PAS** suffisant pour le pattern outbox. Le worker queue **DOIT** tourner :

```bash
QUEUE_CONNECTION=redis  # ou database

# Lancer 1 ou 2 workers en supervisord/systemd
php artisan queue:work --queue=high --tries=4 --backoff=1,5,30,300 --daemon
```

**Sans worker** : les broadcasts s'accumulent en `domain_events` avec `dispatched_at = NULL`. KDS/OSS/POS ne reçoivent rien en realtime, seul le polling 30s en filet de sécurité masque le défaut.

**Health check** : `GET /api/health/ready` vérifie qu'au moins 1 worker tourne récemment.

**Pour le DEV** : `QUEUE_CONNECTION=sync` est OK (pas de worker requis car exécution synchrone dans la requête HTTP). En dev/test uniquement.
```

### 3.2 `.env.example` modification (15 min)

```diff
-# [CRITICAL-PROD] QUEUE_CONNECTION=sync is synchronous and blocks the API.
-QUEUE_CONNECTION=sync
-# QUEUE_CONNECTION=redis  # [PRODUCTION] Uncomment this line in production
-# QUEUE_CONNECTION=database  # Alternative if Redis unavailable
+# [PRODUCTION] redis required since outbox pattern (cf. docs/OUTBOX_PATTERN.md).
+QUEUE_CONNECTION=redis
+# [DEV ONLY] sync runs jobs in the HTTP request (no worker needed).
+# QUEUE_CONNECTION=sync
+# [PROD ALT] database backend if Redis unavailable.
+# QUEUE_CONNECTION=database
```

### 3.3 HealthController extension (3h)

**File:** `app/Http/Controllers/HealthController.php`

Ajouter une méthode `queueWorkerHealth()` qui vérifie :
- Un job a été dispatché et complété récemment sur la queue `high`.
- Critère : `domain_events.count(WHERE dispatched_at >= NOW() - INTERVAL 5 MINUTE) >= 1` OU `domain_events.count(WHERE created_at < NOW() - INTERVAL 30 SECOND AND dispatched_at IS NULL) == 0`.

```php
public function ready()
{
    $checks = [
        'db' => $this->checkDb(),
        'queue_worker' => $this->checkQueueWorker(),  // NEW
        'broadcast' => $this->checkBroadcastConfig(),  // NEW
    ];

    $healthy = !in_array(false, array_column($checks, 'healthy'), true);
    return response()->json([
        'status' => $healthy ? 'ready' : 'degraded',
        'checks' => $checks,
    ], $healthy ? 200 : 503);
}

private function checkQueueWorker(): array
{
    // [AUDIT-F-015] Verify queue worker is processing outbox events.
    $staleCount = \DB::table('domain_events')
        ->where('created_at', '<', now()->subSeconds(30))
        ->whereNull('dispatched_at')
        ->count();

    if ($staleCount > 10) {
        return [
            'healthy' => false,
            'message' => "Queue worker appears down or lagging: {$staleCount} stale outbox events",
        ];
    }

    return ['healthy' => true, 'stale_count' => $staleCount];
}

private function checkBroadcastConfig(): array
{
    $connection = config('queue.default');
    $broadcastDriver = config('broadcasting.default');

    if ($connection === 'sync' && app()->environment('production')) {
        return [
            'healthy' => false,
            'message' => 'QUEUE_CONNECTION=sync in production is incompatible with outbox pattern',
        ];
    }

    if (in_array($broadcastDriver, ['null', 'log'], true) && app()->environment('production')) {
        return [
            'healthy' => false,
            'message' => "BROADCAST_DRIVER={$broadcastDriver} in production — realtime broadcasts disabled",
        ];
    }

    return ['healthy' => true, 'queue' => $connection, 'broadcast' => $broadcastDriver];
}
```

### 3.4 Monitor command (2h)

**File:** `app/Console/Commands/MonitorOutboxStaleness.php` (nouveau)

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitorOutboxStaleness extends Command
{
    protected $signature = 'foodking:outbox:monitor {--threshold=10 : Stale event count alert threshold}';
    protected $description = 'Monitor outbox staleness; alerts if too many events stuck undispatched';

    public function handle(): int
    {
        $threshold = (int) $this->option('threshold');
        $stale = DB::table('domain_events')
            ->where('created_at', '<', now()->subSeconds(30))
            ->whereNull('dispatched_at')
            ->count();

        $oldest = DB::table('domain_events')
            ->where('created_at', '<', now()->subSeconds(30))
            ->whereNull('dispatched_at')
            ->min('created_at');

        if ($stale > $threshold) {
            $message = "[OUTBOX STALE] {$stale} events stuck (oldest: {$oldest}). Worker may be down.";
            Log::error($message);
            $this->error($message);
            return Command::FAILURE;
        }

        $this->info("[OK] {$stale} stale events (threshold: {$threshold}).");
        return Command::SUCCESS;
    }
}
```

Schedule dans `app/Console/Kernel.php` :
```php
$schedule->command('foodking:outbox:monitor --threshold=10')
    ->everyMinute()
    ->withoutOverlapping();
```

### 3.5 Tests (2h)

**File:** `tests/Feature/Realtime/OutboxDeliveryTest.php` (nouveau)

```php
<?php

namespace Tests\Feature\Realtime;

use Tests\TestCase;
use App\Models\DomainEvent;
use App\Events\OrderCreated;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

class OutboxDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function order_created_persists_domain_event_in_outbox(): void
    {
        $order = Order::factory()->create();

        event(new OrderCreated($order));

        $this->assertDatabaseHas('domain_events', [
            'aggregate_id' => $order->id,
            'event_type' => OrderCreated::class,
        ]);
    }

    /** @test */
    public function in_sync_mode_dispatched_at_is_set_after_event(): void
    {
        config(['queue.default' => 'sync']);
        $order = Order::factory()->create();

        event(new OrderCreated($order));

        $this->assertDatabaseHas('domain_events', [
            'aggregate_id' => $order->id,
        ]);

        $eventRow = DomainEvent::where('aggregate_id', $order->id)->first();

        $this->assertNotNull(
            $eventRow->dispatched_at,
            'In sync mode, dispatched_at MUST be set immediately. If null, broadcast pipeline broken.'
        );
    }

    /** @test */
    public function in_database_queue_mode_without_worker_dispatched_at_remains_null(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $order = Order::factory()->create();

        event(new OrderCreated($order));

        Queue::assertPushedOn('high', \App\Jobs\DispatchDomainEventsJob::class);

        $eventRow = DomainEvent::where('aggregate_id', $order->id)->first();
        $this->assertNull(
            $eventRow->dispatched_at,
            'Without worker processing the queue, dispatched_at MUST remain NULL — confirms the trap.'
        );
    }

    /** @test */
    public function health_check_ready_fails_when_too_many_stale_events(): void
    {
        // Insert 11 stale events
        for ($i = 0; $i < 11; $i++) {
            DomainEvent::create([
                'event_type' => 'TestEvent',
                'aggregate_type' => 'Order',
                'aggregate_id' => $i,
                'branch_id' => 1,
                'payload' => [],
                'channel' => json_encode(['test']),
                'broadcast_as' => 'TestBroadcast',
                'occurred_at' => now()->subMinute(),
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ]);
        }

        $r = $this->getJson('/api/health/ready');
        $r->assertStatus(503);
        $r->assertJsonPath('checks.queue_worker.healthy', false);
    }
}
```

---

## 4. ACCEPTANCE CRITERIA

| AC | Critère |
|---|---|
| AC1 | `docs/REALTIME_SETUP.md` ne contient plus la phrase "sync est suffisant" |
| AC2 | `.env.example` defaults sont sécurisés pour prod |
| AC3 | `GET /api/health/ready` retourne 503 si > 10 events stale |
| AC4 | `GET /api/health/ready` retourne 503 si `QUEUE_CONNECTION=sync` en prod |
| AC5 | `php artisan foodking:outbox:monitor` détecte les stales |
| AC6 | Monitor scheduled toutes les minutes |
| AC7 | Test outbox delivery couvre sync + database modes |
| AC8 | Pas de modification du code applicatif (purement ops + monitoring) |

---

## 5. ANTI-DRIFT

- [ ] Pas de modif du code OrderService / FrontendOrderService
- [ ] Pas de modif des Listeners outbox
- [ ] Pas de modif de DispatchDomainEventsJob
- [ ] Tests utilisent Queue::fake() pour ne pas réellement déclencher
- [ ] Pas de touche frozen zones

---

## 6. RISK

| Risk | Mit |
|---|---|
| Health check trop strict bloque déploiement | Threshold configurable (default 10) |
| Monitor cron miss → no alert | Ajouter check secondaire dans health endpoint |
| `.env.example` change casse onboarding new dev | Doc README mise à jour mention le changement |

---

## 7. DEFINITION OF DONE

- [ ] 4 tests verts dans `tests/Feature/Realtime/OutboxDeliveryTest.php`
- [ ] Health check étendu vérifié manuel `curl /api/health/ready`
- [ ] Monitor command testé `php artisan foodking:outbox:monitor`
- [ ] Doc REALTIME_SETUP.md reviewée
- [ ] `.env.example` validé par owner ou diff git review
- [ ] Commit : `audit(F-015): production blocker queue config + health + monitoring`
- [ ] Report `reports/execution/audit_2026-05-07/REPORT_F015_queue_config.md`
- [ ] Graphiti push
