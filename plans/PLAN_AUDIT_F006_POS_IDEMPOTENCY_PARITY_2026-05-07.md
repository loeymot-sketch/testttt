# PLAN_AUDIT_F006 — POS Idempotency Parity with Kiosk
**Severity:** P1 — Double-clicks caisse renvoient 422 au lieu de l'order existant
**Owner agent:** Agent D
**Sprint:** S3
**Estimated:** 1 jour-agent
**Frozen-zone override:** NO

---

## 0. STOP CHECKLIST

| # | Q | A |
|---|---|---|
| 1 | Why ? | POS ne catch PAS QueryException 23000 sur idempotency_key UNIQUE → race produit 422 au lieu de retourner l'existing comme prévu. Kiosk gère, POS pas. Asymétrie. |
| 2 | What ? | Aligner POS sur le pattern Kiosk : Cache::lock préventif + catch 23000 + retour existing |
| 3 | Where ? | `OrderService::posOrderStore` lignes 549-559 et 561-563 (try/catch) |
| 4 | Who ? | Caissier (POS UI), tests POS |
| 5 | How ? | `tests/Feature/Pos/PosIdempotencyTest.php` + tests existants |
| 6 | When rollback ? | Si idempotency casse le path normal (un seul call) |

---

## 1. THINK

[`OrderService.php:549-563`](app/Services/OrderService.php:549) :

```php
public function posOrderStore(PosOrderRequest $request): object
{
    // [AUDIT-P49-BUG6] Idempotency
    $idempotencyKey = $request->header('X-Idempotency-Key');
    if ($idempotencyKey) {
        $existing = Order::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }
    }

    try {
        $order = null;
        DB::transaction(function () use (...) { ... });
        // ...
    } catch (Exception $exception) {
        Log::info($exception->getMessage());
        throw new Exception(QueryExceptionLibrary::message($exception), 422);
    }
}
```

Issues :

1. **Check hors transaction** : 2 requêtes simultanées peuvent toutes deux voir `$existing = null`, toutes deux entrer dans transaction, l'une succeed, l'autre crash 23000 (UNIQUE) → catch generic Exception → 422 au lieu d'idempotent return.
2. **Pas de Cache::lock** préventif comme Kiosk fait (`FrontendOrderService.php:131-135`).

Comparer Kiosk recovery [`FrontendOrderService.php:592-602`](app/Services/FrontendOrderService.php:592) :
```php
} catch (\Illuminate\Database\QueryException $qe) {
    if ($qe->getCode() === '23000' && $idempotencyKey) {
        $existing = FrontendOrder::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) return $existing;
    }
    // ...
}
```

→ Pattern complet Kiosk : Cache::lock + check existing + transaction + 23000 catch.

## 2. PLAN

### 2.1 Refactor posOrderStore

**BEFORE :**

```php
public function posOrderStore(PosOrderRequest $request): object
{
    $idempotencyKey = $request->header('X-Idempotency-Key');
    if ($idempotencyKey) {
        $existing = Order::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) return $existing;
    }
    try {
        $order = null;
        DB::transaction(function () use (...) { /* ... */ });
        // ...
    } catch (Exception $exception) {
        Log::info($exception->getMessage());
        throw new Exception(QueryExceptionLibrary::message($exception), 422);
    }
}
```

**AFTER :**

```php
public function posOrderStore(PosOrderRequest $request): object
{
    $idempotencyLock = null;
    $idempotencyKey = $request->header('X-Idempotency-Key');

    // [AUDIT-F-006] Aligned with Kiosk pattern (FrontendOrderService::myOrderStore).
    // Cache::lock preventive serialization + check existing + atomic transaction
    // + 23000 recovery on race.
    if ($idempotencyKey) {
        $branchId = (int) $request->branch_id;
        $idempotencyLock = \Illuminate\Support\Facades\Cache::lock(
            'pos_order_idempotency_' . sha1($branchId . '|' . $idempotencyKey),
            10
        );
        $idempotencyLock->block(5);

        $existing = Order::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            optional($idempotencyLock)->release();
            return $existing;
        }
    }

    try {
        $order = null;
        DB::transaction(function () use ($request, &$order, $idempotencyKey) {
            // ... existing transaction body unchanged
        });
        return $this->order;
    } catch (\Illuminate\Database\QueryException $qe) {
        // [AUDIT-F-006] 23000 recovery — duplicate idempotency_key caught at
        // DB level (race past the in-memory check). Return existing.
        if ($qe->getCode() === '23000' && $idempotencyKey) {
            $existing = Order::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                Log::info('[POS Idempotency] Duplicate caught at DB — returning existing #' . $existing->id);
                return $existing;
            }
        }
        Log::info($qe->getMessage());
        throw new Exception(QueryExceptionLibrary::message($qe), 422);
    } catch (Exception $exception) {
        Log::info($exception->getMessage());
        throw new Exception(QueryExceptionLibrary::message($exception), 422);
    } finally {
        if ($idempotencyLock) {
            optional($idempotencyLock)->release();
        }
    }
}
```

### 2.2 Vérifier UNIQUE constraint

```bash
grep -rn "idempotency_key" database/migrations/ --include="*.php"
```

S'assurer que la constraint UNIQUE existe sur `orders.idempotency_key`. Si non → migration séparée à créer (mais probablement déjà là car le mécanisme actuel l'utilise).

## 3. BUILD

### 3.1 Test rouge (1h)

`tests/Feature/Pos/PosIdempotencyTest.php` :

```php
/** @test */
public function returns_existing_order_on_sequential_duplicate_key(): void
{
    $payload = $this->validPosPayload();
    $key = 'POS-IDEMP-' . uniqid();

    $r1 = $this->withHeaders(['X-Idempotency-Key' => $key])
        ->postJson('/api/admin/pos', $payload);
    $id1 = $r1->json('data.id');

    $r2 = $this->withHeaders(['X-Idempotency-Key' => $key])
        ->postJson('/api/admin/pos', $payload);
    $id2 = $r2->json('data.id');

    $this->assertEquals($id1, $id2, 'Sequential duplicate key MUST return same order id.');
    $this->assertEquals(1, Order::where('idempotency_key', $key)->count());
}

/** @test */
public function recovers_existing_order_on_race_via_23000_catch(): void
{
    // Pre-create order with idempotency_key to simulate race
    $payload = $this->validPosPayload();
    $key = 'POS-RACE-' . uniqid();

    Order::factory()->create([
        'idempotency_key' => $key,
        'branch_id' => $payload['branch_id'],
    ]);

    $r = $this->withHeaders(['X-Idempotency-Key' => $key])
        ->postJson('/api/admin/pos', $payload);

    $r->assertStatus(200);
    $this->assertEquals(1, Order::where('idempotency_key', $key)->count());
}

/** @test */
public function key_is_truncated_to_64_chars(): void
{
    $longKey = str_repeat('A', 100);
    $r = $this->withHeaders(['X-Idempotency-Key' => $longKey])
        ->postJson('/api/admin/pos', $this->validPosPayload());

    $order = Order::find($r->json('data.id'));
    $this->assertEquals(64, strlen($order->idempotency_key));
    $this->assertEquals(str_repeat('A', 64), $order->idempotency_key);
}
```

### 3.2 Implementation (2h)

§2.1.

### 3.3 Verification (1h)

```bash
./vendor/bin/phpunit tests/Feature/Pos/PosIdempotencyTest.php
./vendor/bin/phpunit tests/Feature/Pos/    # full suite
./vendor/bin/phpunit tests/Feature/Payment/
./vendor/bin/phpunit tests/Feature/POSComprehensiveTest.php
```

## 4. ACCEPTANCE CRITERIA

| AC | Critère |
|---|---|
| AC1 | Sequential duplicate key → 200 + same id |
| AC2 | Race (23000 catch) → 200 + existing |
| AC3 | Key truncate à 64 char |
| AC4 | Pas de régression POS suite |
| AC5 | Cache::lock relâché en finally |

## 5. ANTI-DRIFT

- [ ] Pas de modif frozen zones
- [ ] Pas de modif `idempotency_key` UNIQUE constraint
- [ ] Header `X-Idempotency-Key` (case sensitive) inchangé

## 6. RISK

| Risk | Mit |
|---|---|
| Lock TTL 10s trop court si SSOT lent | Si timeout fréquent post-merge, augmenter à 30s (couplé F-005) |
| `optional($lock)->release()` dans branch sans lock | `optional()` no-op safe |

## 7. DEFINITION OF DONE

- [ ] 3 tests verts
- [ ] Suite POS+Payment verte
- [ ] Commit : `audit(F-006): align POS idempotency with kiosk pattern (lock + 23000 recovery)`
- [ ] Report + Graphiti
