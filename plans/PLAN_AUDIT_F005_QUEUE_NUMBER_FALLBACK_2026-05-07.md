# PLAN_AUDIT_F005 — Queue Number Fallback Collision
**Severity:** P1 — Collision queue display + ambiguïté CRM
**Owner agent:** Agent D
**Sprint:** S3
**Estimated:** 0.5 jour-agent
**Frozen-zone override:** NO

---

## 0. STOP CHECKLIST

| # | Q | A |
|---|---|---|
| 1 | Why ? | Sur `LockTimeoutException`, fallback `microtime(true)*10 % 9999` est non-monotonique → collision avec séquence légitime du jour, OSS affiche désordre, ambiguïté CRM |
| 2 | What ? | Préfixe `Z` pour fallback (distinct du `A` nominal), monotonique via Cache::increment, plage isolée |
| 3 | Where ? | `OrderService.php:805`, `FrontendOrderService.php:399` |
| 4 | Who ? | Cuisine (KDS), OSS, Caissier (recall order par numero) |
| 5 | How ? | `tests/Feature/QueueNumberFallbackTest.php` |
| 6 | When rollback ? | Si OSS/KDS UI casse sur préfixe Z |

---

## 1. THINK

[`OrderService.php:805`](app/Services/OrderService.php:805) et [`FrontendOrderService.php:399`](app/Services/FrontendOrderService.php:399) :

```php
} catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
    $queueNumber = 'A' . str_pad((int)(microtime(true) * 10) % 9999 + 1, 4, '0', STR_PAD_LEFT);
    Log::warning('[Queue] Lock timeout for branch ' . $this->order->branch_id . ' — fallback queue number used.');
}
```

Problèmes :
- `microtime(true) * 10` change toutes les 100 ms → 2 fallbacks dans la même 100 ms se collisionnent.
- Le préfixe `A` est le même que la séquence nominale → si Z9999 fallback puis A0042 nominal → mélange ordre apparent.
- Aucun metric pour détecter la fréquence du fallback.

## 2. PLAN

### 2.1 Préfixe distinct + monotonique

```php
} catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
    // [AUDIT-F-005] Fallback monotonique — préfixe Z pour distinguer
    // visuellement du chemin nominal A. Counter Cache atomique par
    // (branch, date) — collision impossible.
    $fallbackKey = 'queue_fallback_' . $this->order->branch_id . '_' . $today;
    $fallbackNum = (int) \Illuminate\Support\Facades\Cache::increment($fallbackKey, 1);
    if ($fallbackNum === 1) {
        // First time today — set TTL
        \Illuminate\Support\Facades\Cache::expire($fallbackKey, 86400);
    }
    $fallbackNum = $fallbackNum % 9999 + 1; // wrap if extreme
    $queueNumber = 'Z' . str_pad($fallbackNum, 4, '0', STR_PAD_LEFT);

    \Illuminate\Support\Facades\Log::warning('[Queue] Lock timeout fallback', [
        'branch_id' => $this->order->branch_id,
        'fallback_number' => $queueNumber,
        'lock_key' => $lockKey,
    ]);

    // Metric : queue.lock_timeout_fallback_used{branch_id}
}
```

**Note** : `Cache::increment` n'a pas de `expire` direct ; utiliser `Cache::add($key, 0, $ttl)` puis increment. Ajuster :

```php
$fallbackKey = 'queue_fallback_' . $this->order->branch_id . '_' . $today;
\Illuminate\Support\Facades\Cache::add($fallbackKey, 0, now()->addDay());
$fallbackNum = (int) \Illuminate\Support\Facades\Cache::increment($fallbackKey, 1);
$fallbackNum = (($fallbackNum - 1) % 9999) + 1;
$queueNumber = 'Z' . str_pad($fallbackNum, 4, '0', STR_PAD_LEFT);
```

### 2.2 Cause racine — étendre TTL lock principal

Lock principal actuel : `Cache::lock($lockKey, 10)` → 10s TTL. Si transaction prend >10s (legacy non-SSOT lent), lock relâche → autres requêtes conccurentes peuvent allouer le même MAX+1.

→ Étendre TTL à **30s** (sans changer block(5) attente). Si vraie deadlock, 30s permet rollback transaction parent par PHP timeout (`max_execution_time` default 30s). 

```php
$lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 30); // 10 → 30
```

## 3. BUILD

### 3.1 Test rouge (30 min)

`tests/Feature/QueueNumberFallbackTest.php` :

```php
/** @test */
public function fallback_uses_z_prefix_on_lock_timeout(): void
{
    Cache::shouldReceive('lock')->andReturnUsing(function () {
        $mock = Mockery::mock();
        $mock->shouldReceive('block')->andThrow(new LockTimeoutException);
        $mock->shouldReceive('release')->andReturn(true);
        return $mock;
    });

    // ... create order normally — should fallback
    $r = $this->postJson('/api/admin/pos', $this->validPosPayload());
    $order = Order::find($r->json('data.id'));
    $this->assertStringStartsWith('Z', $order->queue_number);
    $this->assertMatchesRegularExpression('/^Z\d{4}$/', $order->queue_number);
}

/** @test */
public function fallback_is_monotonic_when_lock_repeatedly_times_out(): void
{
    // 3 calls fallback en sequence → Z0001, Z0002, Z0003
}

/** @test */
public function fallback_does_not_collide_with_nominal_path(): void
{
    // 1 call nominal A0001, puis 1 call fallback → doit être Z* pas A*
}
```

### 3.2 Implementation (30 min)

§2.1 + §2.2.

### 3.3 Verification

```bash
./vendor/bin/phpunit tests/Feature/QueueNumberFallbackTest.php
./vendor/bin/phpunit tests/Feature/POSComprehensiveTest.php
./vendor/bin/phpunit tests/Feature/KioskFrontendComprehensiveTest.php
```

### 3.4 OSS/KDS UI check

```bash
grep -rn "queue_number" resources/js/components/admin/ --include="*.vue"
```

Vérifier que OSS et KDS affichent indistinctement A et Z (pas de filter regex sur `^A`). Si fallback `Z` ne s'affiche pas → ajouter test Vue.

## 4. ACCEPTANCE CRITERIA

| AC | Critère |
|---|---|
| AC1 | Fallback préfixe Z |
| AC2 | Monotonique par (branch, day) |
| AC3 | Pas de collision avec nominal A |
| AC4 | TTL lock étendu à 30s |
| AC5 | Metric warning incluse dans log |
| AC6 | OSS/KDS affichent Z* normalement |

## 5. ANTI-DRIFT

- [ ] Préfixe nominal A inchangé
- [ ] Pas de modification frozen zones

## 6. RISK

| Risk | Mit |
|---|---|
| Cache key collision entre branches | Key inclut branch_id |
| Cache reset (Redis flush) entre 2 fallbacks | Counter recommence à 1 — acceptable, journée probablement nouvelle |

## 7. DEFINITION OF DONE

- [ ] 3 tests verts
- [ ] OSS/KDS verifiés
- [ ] Commit : `audit(F-005): monotonic Z-prefixed queue number fallback`
- [ ] Report + Graphiti
