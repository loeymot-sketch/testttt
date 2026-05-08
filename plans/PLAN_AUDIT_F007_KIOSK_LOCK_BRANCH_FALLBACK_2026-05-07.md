# PLAN_AUDIT_F007 — Kiosk Lock Branch Fallback to 0
**Severity:** P1 — Cross-branche idempotency lock collision
**Owner agent:** Agent D
**Sprint:** S3
**Estimated:** 0.5 jour-agent
**Frozen-zone override:** NO

---

## 0. STOP CHECKLIST

| # | Q | A |
|---|---|---|
| 1 | Why ? | Si Auth perd contexte, `lockBranchId` retombe à 0 → idempotency lock cross-branche → garantie idempotency cassée |
| 2 | What ? | Hard-fail 401 si Auth absent, 403 si KioskMachine introuvable. Pas de fallback à 0. |
| 3 | Where ? | `FrontendOrderService.php:124-126` |
| 4 | Who ? | Kiosks (token expiré, middleware cassé) |
| 5 | How ? | `tests/Feature/Kiosk/KioskAuthGuardTest.php` |
| 6 | When rollback ? | Si test legit kiosk auth échoue 401/403 |

---

## 1. THINK

[`FrontendOrderService.php:124-126`](app/Services/FrontendOrderService.php:124) :

```php
$lockBranchId = (int) (\App\Models\KioskMachine::where('user_id', Auth::id())->value('branch_id')
    ?? (Auth::user()?->branch_id ?? 0));
```

Si `Auth::id() === null`, le query KioskMachine retourne null, `Auth::user()` est null, `?? 0` fallback. Lock est sur branch 0.

Conséquences :
- 2 kiosks avec auth cassée envoyant la même `X-Idempotency-Key` (UUID v4 collision pratiquement impossible mais théoriquement possible) sont sérialisés sur le même lock.
- Le `branch_id` final de l'order (résolu plus tard dans transaction) peut différer du `lockBranchId` → idempotency promise broken.
- Hide bugs middleware : on doit voir l'auth missing tout de suite.

## 2. PLAN

### 2.1 Hard-fail dans myOrderStore

**BEFORE :**

```php
public function myOrderStore(OrderRequest $request): object
{
    $this->loyaltyApplied = false;
    $idempotencyLock = null;
    $lockBranchId = (int) (\App\Models\KioskMachine::where('user_id', Auth::id())->value('branch_id')
        ?? (Auth::user()?->branch_id ?? 0));
```

**AFTER :**

```php
public function myOrderStore(OrderRequest $request): object
{
    $this->loyaltyApplied = false;
    $idempotencyLock = null;

    // [AUDIT-F-007] Hard-fail when context is incomplete — never fall back to
    // branch_id=0 which would cross-branch idempotency lock and break the
    // idempotency contract.
    $authId = Auth::id();
    if (!$authId) {
        throw new \Exception('Unauthenticated kiosk request', 401);
    }

    $kioskMachine = \App\Models\KioskMachine::where('user_id', $authId)->first();
    if (!$kioskMachine) {
        throw new \Exception('Kiosk machine not registered for this user', 403);
    }

    $lockBranchId = (int) $kioskMachine->branch_id;
    if ($lockBranchId <= 0) {
        throw new \Exception('Kiosk machine has invalid branch_id', 422);
    }
```

### 2.2 Réutiliser `$kioskMachine` dans la transaction

Plus loin dans la fonction (ligne 158) :

```php
$kiosk = \App\Models\KioskMachine::where('user_id', Auth::user()->id)->first();
```

→ remplacer par :

```php
$kiosk = $kioskMachine; // already resolved above
```

Économie d'une query DB.

### 2.3 OrderController gestion des nouveaux exceptions

[`OrderController::store`](app/Http/Controllers/Frontend/OrderController.php:38) catch generic Exception → 422.

Modifier pour propager les codes HTTP :

```php
public function store(OrderRequest $request): \Illuminate\Http\Response | OrderDetailsResource | ...
{
    try {
        $order = $this->frontendOrderService->myOrderStore($request);
        return (new OrderDetailsResource($order))->additional([
            'loyalty_applied' => $this->frontendOrderService->loyaltyApplied,
        ]);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
        throw $http;
    } catch (Exception $exception) {
        $code = $exception->getCode();
        $httpStatus = in_array($code, [401, 403, 422], true) ? $code : 422;
        return response(['status' => false, 'message' => $exception->getMessage()], $httpStatus);
    }
}
```

## 3. BUILD

### 3.1 Test rouge

`tests/Feature/Kiosk/KioskAuthGuardTest.php` :

```php
/** @test */
public function rejects_kiosk_order_without_auth(): void
{
    $r = $this->postJson('/api/frontend/order', $this->validKioskPayload());
    $r->assertStatus(401);
}

/** @test */
public function rejects_kiosk_order_when_user_has_no_kiosk_machine(): void
{
    $randomUser = User::factory()->create(['branch_id' => 1]);
    $this->actingAs($randomUser, 'sanctum');
    $r = $this->postJson('/api/frontend/order', $this->validKioskPayload());
    $r->assertStatus(403);
}

/** @test */
public function accepts_kiosk_order_with_full_context(): void
{
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['branch_id' => $branch->id]);
    KioskMachine::factory()->create(['user_id' => $user->id, 'branch_id' => $branch->id]);
    $this->actingAs($user, 'sanctum');
    $r = $this->postJson('/api/frontend/order', $this->validKioskPayload());
    $r->assertStatus(201);
}
```

### 3.2 Implementation (1h)

§2.1, §2.2, §2.3.

### 3.3 Verification

```bash
./vendor/bin/phpunit tests/Feature/Kiosk/KioskAuthGuardTest.php
./vendor/bin/phpunit tests/Feature/KioskFrontendComprehensiveTest.php
./vendor/bin/phpunit tests/Feature/KioskScopeIsolationTest.php
./vendor/bin/phpunit tests/Feature/KioskAuthTest.php
```

## 4. ACCEPTANCE CRITERIA

| AC | Critère |
|---|---|
| AC1 | Pas d'auth → 401 |
| AC2 | User sans KioskMachine → 403 |
| AC3 | Branch_id <= 0 → 422 |
| AC4 | Auth + KioskMachine valid → 201 (path nominal) |
| AC5 | Pas de query KioskMachine doublée |

## 5. ANTI-DRIFT

- [ ] Pas de modif frozen zones
- [ ] Pas de fallback "best effort" — soit complet, soit reject
- [ ] Tests existants utilisant actingAs($kioskUser) restent verts

## 6. RISK

| Risk | Mit |
|---|---|
| Tests existants passent avec auth incomplete (legacy fixtures) | Identifier et adapter via factory KioskMachine |
| Changement de code HTTP (401/403) casse client kiosk | Frontend KioskApp doit déjà gérer 401 (refresh token) ; 403 = redirect kiosk-login |

## 7. DEFINITION OF DONE

- [ ] 3 tests verts
- [ ] Pas de query KioskMachine doublée
- [ ] Suite Kiosk verte
- [ ] Commit : `audit(F-007): hard-fail kiosk order without auth + machine context`
- [ ] Report + Graphiti
