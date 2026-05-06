# PLAN_P11_IDEMPOTENCY_KEY_MIDDLEWARE — F-VERIFY-09-02

**Date** : 2026-05-06
**Train** : A V1 release prep
**Finding** : F-VERIFY-09-02 (P0 frozen-zone)
**Référence verdict** : `docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md` §3.4 et §4
**Auteur** : agent Plan, plan destiné à Cursor/Codex (exécution)
**Statut** : 🟢 PRÊT À EXÉCUTER

---

## 0. Mismatches de routes corrigés (vs énoncé initial)

- `/api/pos` → réelle = `POST /api/admin/pos` (`routes/api.php:695`).
- `/api/admin/pos-order/change-payment-status/{order}` (`:800`). OK.
- `/api/frontend/select-delivery` → **n'existe pas** ; remplacé par `POST /api/admin/pos-order/select-delivery-boy/{order}` (`:802`) + `POST /api/admin/online-order/select-delivery-boy/{order}` (`:816`).
- `/api/admin/order/payment-confirm` → **n'existe pas** ; remplacé par `POST /api/frontend/order/{frontendOrder}/payment-confirm` (`:1028`).
- Ajout symétrie : `POST /api/frontend/order` (`:1025`) — `FrontendOrderService::storeOrUpdate` lit déjà `X-Idempotency-Key` (`app/Services/FrontendOrderService.php:139`).

---

## 1. Contexte + invariants

### 1.1 Contexte FoodKing
FoodKing POS SaaS. Train A V1 release prep. F-VERIFY-09-02 OPEN P0 frozen-zone : protection idempotence **uniquement applicative** (pré-check `Order::where('idempotency_key')` dans `OrderService::posOrderStore`, UNIQUE composite `(branch_id, idempotency_key)`, catch `QueryException 23000`). Header `X-Idempotency-Key` **optionnel** côté serveur — client tiers (intégration POS, terminal CB third-party) qui omet header peut dupliquer.

### 1.2 Invariants à respecter
Référence : `feedback_cv1_mode_operatoire.md` + `docs/audit/POS_AUDIT_MASTER_PLAN_2026-05-06.md`.

- **Branch isolation** : la clé d'idempotence DOIT inclure `branch_id`. Sentinel `IdempotencyRecoveryBranchScopedTest` (4/4 PASS) garantit aujourd'hui le scope DB `(branch_id, idempotency_key)`. Le middleware DOIT s'aligner — `branch_id=0` (Admin global) reste légitime mais ne DOIT PAS leak entre branches non-admin.
- **Idempotence applicative existante** : `OrderService::findExistingOrderForIdempotencyRecovery($key, $branchId)` (`app/Services/OrderService.php:2157`) et `FrontendOrderService::findExistingFrontendOrderForIdempotencyRecovery` restent — middleware HTTP est une **ceinture supplémentaire**, pas un remplacement.
- **Frozen zones** : ne pas modifier `OrderService::posOrderStore`, `changePaymentStatus`, `FrontendOrderService::storeOrUpdate`, ni le UNIQUE DB. Middleware s'insère AVANT eux.
- **28/28 sentinels POS DOIVENT rester PASS** (régression zéro).
- **Pricing SSOT** + **allergen snapshot POS-9.4.BL.1** + **NF525 fiscal** inchangés.

### 1.3 Spec issue de VERIFY-09
> "middleware HTTP `IdempotencyKeyMiddleware`, scope `(branch_id, user_id, key)`, TTL Redis 24h, replay-cache, 422 si absent sur POST POS/payment/select-delivery"

---

## 2. Décisions architecturales (load-bearing)

| Décision | Choix | Rationale |
|---|---|---|
| **Stockage** | Redis via `Cache::store('redis')` + `SET NX EX` natif Redis pour atomicité | TTL natif, latence sub-ms, déjà configuré. Pas de migration DB. |
| **Scope clé** | `idempotency:v1:{branch_id}:{user_id}:{sha256(key)}` | Aligné sentinel branch-scoped. Empêche cross-branch leak ET cross-user replay. SHA256 normalise longueur (64 hex). |
| **Lecture `branch_id`** | 1) `auth()->user()->branch_id` si > 0 ; 2) `$request->input('branch_id')` ; 3) sinon **rejet 422** (jamais `0` implicite). Admin (`branch_id=0` + role Admin) → bypass scope branch. | Aligné `posOrderStore` ligne 566 (W9-AUDIT PROD-2). |
| **TTL** | 24h par défaut, `config('idempotency.ttl_seconds', 86400)` | Spec VERIFY-09. |
| **Replay-cache** | Stocker `{status, headers, body_b64, created_at}` JSON sérialisé. Au replay : retourner même status/body + ajouter header `Idempotency-Replayed: true` et `Idempotency-Stored-At: <ISO8601>`. | Conforme RFC draft IETF idempotency-key-header. |
| **422 si header absent** | `MissingIdempotencyKeyException` rendu en 422. Liste route opt-in via config `idempotency.required_routes`. | Routes non listées : skip (compat ascendante). |
| **Redis down** | **Fail-CLOSED par défaut** en prod (HTTP 503 + log). Override via `IDEMPOTENCY_FAIL_OPEN=true`. | DB-UNIQUE backstop existe pour `Order` mais PAS pour `changePaymentStatus`/`paymentConfirm` — fail-open créerait un trou. |
| **Compat applicative** | Middleware AJOUTE une couche cache, ne supprime rien. `findExistingOrderForIdempotencyRecovery` reste appelé en defense-in-depth. | Sentinel `IdempotencyRecoveryBranchScoped` doit rester vert. |
| **Feature flag** | `IDEMPOTENCY_MIDDLEWARE_ENABLED=false` par défaut, true en CI/staging. | Rollback instantané. |
| **Hash payload** | SHA256 du body raw + `branch_id` + `user_id` + clé. Stocké pour détecter "même clé, payload différent" → conflit 409. | Conforme draft IETF §2.6. |

---

## 3. Fichiers à créer

### 3.1 `app/Http/Middleware/IdempotencyKeyMiddleware.php`

```php
<?php

namespace App\Http\Middleware;

use App\Exceptions\MissingIdempotencyKeyException;
use App\Services\Idempotency\IdempotencyKeyRepository;
use App\Services\Idempotency\IdempotencyStorageUnavailableException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * F-VERIFY-09-02 — HTTP-level idempotency guard.
 *
 * Wraps state-mutating POST endpoints with at-most-once execution scoped on
 * (branch_id, user_id, key). Replay within TTL returns cached response with
 * `Idempotency-Replayed: true`. Missing header on required route → 422.
 *
 * Compat: app-level UNIQUE (branch_id, idempotency_key) and
 * `OrderService::findExistingOrderForIdempotencyRecovery` remain as defense-
 * in-depth (cf. §1.2).
 */
final class IdempotencyKeyMiddleware
{
    public const HEADER = 'X-Idempotency-Key';
    public const REPLAY_HEADER = 'Idempotency-Replayed';
    public const STORED_AT_HEADER = 'Idempotency-Stored-At';
    public const CONFLICT_HEADER = 'Idempotency-Key-Conflict';

    public function __construct(
        private readonly IdempotencyKeyRepository $repository,
    ) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! config('idempotency.enabled', false)) {
            return $next($request);
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->header(self::HEADER));
        $isRequired = $this->isRouteRequired($request);

        if ($key === '') {
            if ($isRequired) {
                throw new MissingIdempotencyKeyException(
                    sprintf('Header %s requis pour cette opération.', self::HEADER)
                );
            }
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $key)) {
            throw new MissingIdempotencyKeyException(
                sprintf('Header %s invalide (8-64 chars ASCII).', self::HEADER)
            );
        }

        $branchId = $this->resolveBranchId($request);
        $userId = (int) ($request->user()?->id ?? 0);

        if ($branchId < 0 || $userId <= 0) {
            throw new MissingIdempotencyKeyException(
                'Idempotency requires authenticated user with resolvable branch_id.'
            );
        }

        $payloadHash = hash('sha256', $request->getContent() ?: '');
        $scopedKey = sprintf('idempotency:v1:%d:%d:%s', $branchId, $userId, hash('sha256', $key));

        try {
            // Phase 1 — try replay first
            $replay = $this->repository->find($scopedKey);
            if ($replay !== null) {
                if ($replay->payloadHash !== $payloadHash) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Idempotency key reused with different payload.',
                        'code' => 'IDEMPOTENCY_KEY_CONFLICT',
                    ], 409, [self::CONFLICT_HEADER => 'true']);
                }
                return $this->rebuildReplayResponse($replay);
            }

            // Phase 2 — atomic acquire (SET NX EX)
            $acquired = $this->repository->acquire(
                $scopedKey, $payloadHash, (int) config('idempotency.ttl_seconds', 86400)
            );
            if (! $acquired) {
                $replay = $this->repository->waitForCompletion(
                    $scopedKey, (int) config('idempotency.race_wait_ms', 1500)
                );
                if ($replay !== null) {
                    return $this->rebuildReplayResponse($replay);
                }
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Idempotent request already in flight; retry shortly.',
                    'code' => 'IDEMPOTENCY_IN_FLIGHT',
                ], 425);
            }
        } catch (IdempotencyStorageUnavailableException $e) {
            Log::warning('[IdempotencyMiddleware] storage unavailable', ['error' => $e->getMessage()]);
            if (config('idempotency.fail_open', false)) {
                return $next($request); // bypass — relies on app-layer UNIQUE
            }
            return new JsonResponse(
                ['success' => false, 'message' => 'Idempotency storage unavailable.'], 503
            );
        }

        // Phase 3 — execute and cache
        try {
            $response = $next($request);
        } catch (\Throwable $t) {
            $this->repository->release($scopedKey);
            throw $t;
        }

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->repository->complete(
                $scopedKey, $response, $payloadHash, (int) config('idempotency.ttl_seconds', 86400)
            );
        } else {
            $this->repository->release($scopedKey);
        }

        return $response;
    }

    private function isRouteRequired(Request $request): bool
    {
        $route = $request->route();
        if ($route === null) return false;
        $name = $route->getName() ?? '';
        $required = (array) config('idempotency.required_routes', []);
        foreach ($required as $pattern) {
            if (str_starts_with($pattern, 'name:')) {
                if ($name === substr($pattern, 5)) return true;
            } elseif ($request->is(ltrim($pattern, '/'))) {
                return true;
            }
        }
        return false;
    }

    private function resolveBranchId(Request $request): int
    {
        $user = $request->user();
        $authBranchId = (int) ($user?->branch_id ?? -1);

        if ($authBranchId === 0 && $user && method_exists($user, 'hasRole') && $user->hasRole('Admin')) {
            $payloadBranchId = (int) $request->input('branch_id', 0);
            return $payloadBranchId > 0 ? $payloadBranchId : 0;
        }

        if ($authBranchId > 0) {
            return $authBranchId;
        }

        return (int) $request->input('branch_id', -1);
    }

    private function rebuildReplayResponse(\App\Services\Idempotency\IdempotencyRecord $rec): SymfonyResponse
    {
        $response = new Response(base64_decode($rec->bodyBase64), $rec->status, $rec->headers);
        $response->headers->set(self::REPLAY_HEADER, 'true');
        $response->headers->set(self::STORED_AT_HEADER, $rec->createdAt);
        return $response;
    }
}
```

### 3.2 `app/Services/Idempotency/IdempotencyKeyRepository.php` (interface)

```php
<?php

namespace App\Services\Idempotency;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

interface IdempotencyKeyRepository
{
    /** @throws IdempotencyStorageUnavailableException */
    public function find(string $scopedKey): ?IdempotencyRecord;

    /** @throws IdempotencyStorageUnavailableException */
    public function acquire(string $scopedKey, string $payloadHash, int $ttlSeconds): bool;

    public function complete(string $scopedKey, SymfonyResponse $response, string $payloadHash, int $ttlSeconds): void;

    public function release(string $scopedKey): void;

    public function waitForCompletion(string $scopedKey, int $waitMs): ?IdempotencyRecord;
}
```

### 3.3 `app/Services/Idempotency/IdempotencyRecord.php` (DTO)

```php
<?php

namespace App\Services\Idempotency;

final class IdempotencyRecord
{
    public function __construct(
        public readonly int    $status,
        public readonly array  $headers,
        public readonly string $bodyBase64,
        public readonly string $payloadHash,
        public readonly string $createdAt,
        public readonly string $state, // 'PENDING' | 'COMPLETED'
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            status:      (int) $data['status'],
            headers:     (array) ($data['headers'] ?? []),
            bodyBase64:  (string) ($data['body_b64'] ?? ''),
            payloadHash: (string) ($data['payload_hash'] ?? ''),
            createdAt:   (string) ($data['created_at'] ?? ''),
            state:       (string) ($data['state'] ?? 'COMPLETED'),
        );
    }

    public function toArray(): array
    {
        return [
            'status'       => $this->status,
            'headers'      => $this->headers,
            'body_b64'     => $this->bodyBase64,
            'payload_hash' => $this->payloadHash,
            'created_at'   => $this->createdAt,
            'state'        => $this->state,
        ];
    }
}
```

### 3.4 `app/Services/Idempotency/RedisIdempotencyKeyRepository.php`

```php
<?php

namespace App\Services\Idempotency;

use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class RedisIdempotencyKeyRepository implements IdempotencyKeyRepository
{
    public function __construct(private readonly string $connection = 'default') {}

    public function find(string $scopedKey): ?IdempotencyRecord
    {
        try {
            $raw = Redis::connection($this->connection)->get($scopedKey);
        } catch (\Throwable $e) {
            throw new IdempotencyStorageUnavailableException($e->getMessage(), 0, $e);
        }
        if ($raw === null || $raw === false) return null;
        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) return null;
        $record = IdempotencyRecord::fromArray($decoded);
        return $record->state === 'COMPLETED' ? $record : null;
    }

    public function acquire(string $scopedKey, string $payloadHash, int $ttlSeconds): bool
    {
        try {
            $placeholder = (new IdempotencyRecord(
                status: 0, headers: [], bodyBase64: '',
                payloadHash: $payloadHash,
                createdAt: now()->toIso8601String(),
                state: 'PENDING',
            ))->toArray();
            $ok = Redis::connection($this->connection)->set(
                $scopedKey, json_encode($placeholder, JSON_THROW_ON_ERROR),
                'EX', $ttlSeconds, 'NX',
            );
            return (bool) $ok;
        } catch (\Throwable $e) {
            throw new IdempotencyStorageUnavailableException($e->getMessage(), 0, $e);
        }
    }

    public function complete(string $scopedKey, SymfonyResponse $response, string $payloadHash, int $ttlSeconds): void
    {
        $record = new IdempotencyRecord(
            status: $response->getStatusCode(),
            headers: $this->safeHeaders($response),
            bodyBase64: base64_encode((string) $response->getContent()),
            payloadHash: $payloadHash,
            createdAt: now()->toIso8601String(),
            state: 'COMPLETED',
        );
        try {
            Redis::connection($this->connection)->set(
                $scopedKey, json_encode($record->toArray(), JSON_THROW_ON_ERROR), 'EX', $ttlSeconds,
            );
        } catch (\Throwable $e) {
            \Log::warning('[Idempotency] complete() failed', ['key' => $scopedKey, 'err' => $e->getMessage()]);
        }
    }

    public function release(string $scopedKey): void
    {
        try { Redis::connection($this->connection)->del($scopedKey); } catch (\Throwable) {}
    }

    public function waitForCompletion(string $scopedKey, int $waitMs): ?IdempotencyRecord
    {
        $deadline = microtime(true) + ($waitMs / 1000);
        do {
            $rec = $this->find($scopedKey);
            if ($rec !== null) return $rec;
            usleep(50_000); // 50ms
        } while (microtime(true) < $deadline);
        return null;
    }

    private function safeHeaders(SymfonyResponse $response): array
    {
        $strip = ['set-cookie','date','x-correlation-id','x-request-id'];
        $out = [];
        foreach ($response->headers->all() as $name => $values) {
            if (in_array(strtolower($name), $strip, true)) continue;
            $out[$name] = is_array($values) ? $values : [$values];
        }
        $out['Content-Type'] = $response->headers->all('content-type') ?: ['application/json'];
        return $out;
    }
}
```

### 3.5 Exceptions

```php
<?php
// app/Services/Idempotency/IdempotencyStorageUnavailableException.php
namespace App\Services\Idempotency;
class IdempotencyStorageUnavailableException extends \RuntimeException {}
```

```php
<?php
// app/Services/Idempotency/IdempotencyConflictException.php
namespace App\Services\Idempotency;
class IdempotencyConflictException extends \RuntimeException {}
```

```php
<?php
// app/Exceptions/MissingIdempotencyKeyException.php
namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MissingIdempotencyKeyException extends \RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $message = 'X-Idempotency-Key header required.', private readonly int $status = 422)
    {
        parent::__construct($message, $status);
    }

    public function getStatusCode(): int { return $this->status; }
    public function getHeaders(): array  { return ['Content-Type' => 'application/json']; }

    public function render($request): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->getMessage(),
            'code' => 'MISSING_IDEMPOTENCY_KEY',
        ], $this->status);
    }
}
```

### 3.6 `tests/Feature/Idempotency/IdempotencyMiddlewareTest.php`

8 scénarios :
1. `test_two_identical_posts_create_only_once_and_replay_second` — header `Idempotency-Replayed: true` sur 2e
2. `test_post_without_header_on_required_route_returns_422` — code `MISSING_IDEMPOTENCY_KEY`
3. `test_post_without_header_on_unprotected_route_passes_through`
4. `test_cross_branch_same_key_results_in_distinct_executions` — sentinel branch isolation
5. `test_replay_after_ttl_expired_executes_anew` — TTL=1s + sleep 2s
6. `test_same_key_different_payload_returns_409` — code `IDEMPOTENCY_KEY_CONFLICT`
7. `test_redis_unavailable_fail_closed_returns_503`
8. `test_redis_unavailable_fail_open_passes_through`

### 3.7 `tests/Feature/Sentinels/IdempotencyMiddlewareSentinel.php`

5 scénarios sur **vraies routes POS** :
1. `test_pos_store_without_idempotency_header_is_rejected_422`
2. `test_pos_store_with_same_key_twice_yields_one_order_and_replay`
3. `test_change_payment_status_without_header_is_rejected_422`
4. `test_payment_confirm_without_header_is_rejected_422`
5. `test_existing_branch_scoped_recovery_still_works_with_middleware_active`

---

## 4. Fichiers à modifier

### 4.1 `app/Http/Kernel.php` — ajouter alias

Dans `$routeMiddleware` :
```php
'idempotency' => \App\Http\Middleware\IdempotencyKeyMiddleware::class,
```

### 4.2 `routes/api.php` — patches ciblés (8 lignes)

| Ligne | Avant | Après |
|---|---|---|
| 695 | `Route::post('/', [PosController::class, 'store'])->middleware('throttle:pos-order-create');` | `...->middleware(['throttle:pos-order-create', 'idempotency']);` |
| 800 | `Route::post('/change-payment-status/{order}', [PosOrderController::class, 'changePaymentStatus'])->middleware('throttle:pos-order-update');` | `...->middleware(['throttle:pos-order-update', 'idempotency']);` |
| 802 | `Route::post('/select-delivery-boy/{order}', [PosOrderController::class, 'selectDeliveryBoy'])->middleware('throttle:pos-order-update');` | `...->middleware(['throttle:pos-order-update', 'idempotency']);` |
| 815 | `Route::post('/change-payment-status/{order}', [OnlineOrderController::class, 'changePaymentStatus']);` | `...->middleware('idempotency');` |
| 816 | `Route::post('/select-delivery-boy/{order}', [OnlineOrderController::class, 'selectDeliveryBoy']);` | `...->middleware('idempotency');` |
| 825 | `Route::post('/change-payment-status/{order}', [AdminTableOrderController::class, 'changePaymentStatus']);` | `...->middleware('idempotency');` |
| 1025 | `Route::post('/', [FrontendOrderController::class, 'store'])->middleware('throttle:kiosk-orders');` | `...->middleware(['throttle:kiosk-orders', 'idempotency']);` |
| 1028 | `Route::post('/{frontendOrder}/payment-confirm', [FrontendOrderController::class, 'paymentConfirm']);` | `...->middleware('idempotency');` |

> **NE PAS** appliquer sur `/api/admin/pos/quote`, `/api/frontend/coupon/coupon-checking`, `/api/admin/pos/walk-in-customer` ni `/api/admin/pos/counter-collect/pending` — lectures / pricing-preview sans effet de bord.

### 4.3 `config/idempotency.php` — créer

```php
<?php

return [
    'enabled' => env('IDEMPOTENCY_MIDDLEWARE_ENABLED', false),
    'ttl_seconds' => (int) env('IDEMPOTENCY_TTL_SECONDS', 86400),
    'race_wait_ms' => (int) env('IDEMPOTENCY_RACE_WAIT_MS', 1500),
    'fail_open' => (bool) env('IDEMPOTENCY_FAIL_OPEN', false),
    'required_routes' => [
        'api/admin/pos',
        'api/admin/pos-order/change-payment-status/*',
        'api/admin/pos-order/select-delivery-boy/*',
        'api/admin/online-order/change-payment-status/*',
        'api/admin/online-order/select-delivery-boy/*',
        'api/admin/table-order/change-payment-status/*',
        'api/frontend/order',
        'api/frontend/order/*/payment-confirm',
    ],
    'redis_connection' => env('IDEMPOTENCY_REDIS_CONNECTION', 'default'),
];
```

### 4.4 `app/Providers/AppServiceProvider.php` — bind interface

```php
$this->app->bind(
    \App\Services\Idempotency\IdempotencyKeyRepository::class,
    fn ($app) => new \App\Services\Idempotency\RedisIdempotencyKeyRepository(
        connection: config('idempotency.redis_connection', 'default'),
    ),
);
```

### 4.5 `app/Exceptions/Handler.php` — pas de modification

`MissingIdempotencyKeyException::render($request)` invoqué automatiquement.

---

## 5. Migration éventuelle

**AUCUNE** en V1 (Redis suffit). Fallback DB possible plus tard (`idempotency_records` table) — backlog hors scope V1.

---

## 6. Step-by-step pour Cursor

### Étape 1 — Pré-vérifications
```bash
git status
php artisan --version
php -r "echo extension_loaded('redis') ? 'redis-ok' : 'redis-MISSING';"
redis-cli ping
vendor/bin/phpunit --filter=IdempotencyRecoveryBranchScopedTest
```

### Étape 2 — Créer (ordre strict)
1. `app/Services/Idempotency/IdempotencyRecord.php`
2. `app/Services/Idempotency/IdempotencyKeyRepository.php`
3. `app/Services/Idempotency/IdempotencyStorageUnavailableException.php`
4. `app/Services/Idempotency/IdempotencyConflictException.php`
5. `app/Services/Idempotency/RedisIdempotencyKeyRepository.php`
6. `app/Exceptions/MissingIdempotencyKeyException.php`
7. `app/Http/Middleware/IdempotencyKeyMiddleware.php`

### Étape 3 — Wiring
8. `config/idempotency.php` — flag false initial
9. `app/Providers/AppServiceProvider.php` — bind interface
10. `app/Http/Kernel.php` — alias `'idempotency'`
11. `routes/api.php` — 8 patches §4.2

### Étape 4 — Tests
12. `tests/Feature/Idempotency/IdempotencyMiddlewareTest.php`
13. `tests/Feature/Sentinels/IdempotencyMiddlewareSentinel.php`

### Étape 5 — Run + sanity
```bash
IDEMPOTENCY_MIDDLEWARE_ENABLED=true vendor/bin/phpunit --filter=IdempotencyMiddlewareTest
IDEMPOTENCY_MIDDLEWARE_ENABLED=true vendor/bin/phpunit --filter=IdempotencyMiddlewareSentinel
IDEMPOTENCY_MIDDLEWARE_ENABLED=true vendor/bin/phpunit tests/Feature/Sentinels --testdox
# Attendu : 28 sentinels existants + 1 nouveau = 29 PASS
IDEMPOTENCY_MIDDLEWARE_ENABLED=true vendor/bin/phpunit --filter=IdempotencyRecoveryBranchScopedTest
# Attendu : 4/4 PASS
IDEMPOTENCY_MIDDLEWARE_ENABLED=false vendor/bin/phpunit tests/Feature/Sentinels
# Attendu : 28/28 PASS (transparent)
```

### Étape 6 — Documentation
14. **Créer** `docs/IDEMPOTENCY.md` (contrat intégrateur tiers)
15. **Modifier** `docs/RATE_LIMITS_MATRIX.md` (section "Idempotency Matrix")
16. **Mettre à jour** `docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md` ligne 139 : F-VERIFY-09-02 🔴 → ✅

### Étape 7 — Commit (PAS de push sans review humaine)
```bash
git add app/Http/Middleware/IdempotencyKeyMiddleware.php \
        app/Services/Idempotency/ \
        app/Exceptions/MissingIdempotencyKeyException.php \
        app/Http/Kernel.php \
        app/Providers/AppServiceProvider.php \
        config/idempotency.php \
        routes/api.php \
        tests/Feature/Idempotency/ \
        tests/Feature/Sentinels/IdempotencyMiddlewareSentinel.php \
        docs/IDEMPOTENCY.md docs/RATE_LIMITS_MATRIX.md docs/audit/

git commit -m "feat(idempotency): HTTP middleware F-VERIFY-09-02 (flag-gated)"
```

---

## 7. Critères d'acceptation

### 7.1 Tests
- [ ] `IdempotencyMiddlewareTest` 8/8 PASS
- [ ] `IdempotencyMiddlewareSentinel` 5/5 PASS
- [ ] `IdempotencyRecoveryBranchScopedTest` 4/4 PASS (inchangé)
- [ ] **0 régression** sur 28 sentinels POS existants
- [ ] CI green avec flag true ET false

### 7.2 Code review
- [ ] Aucun fichier `OrderService.php`, `FrontendOrderService.php`, `PosController.php` modifié (frozen-zone respectée).
- [ ] Aucune modif UNIQUE DB.
- [ ] Flag false par défaut → no-op total.
- [ ] `Idempotency-Replayed: true` sur 2e POST identique (curl manuel).

### 7.3 Manuel staging
```bash
KEY=$(uuidgen)
curl -X POST https://staging.foodking/api/admin/pos \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Idempotency-Key: $KEY" \
  -d '{"branch_id":1,"customer_id":42,"items":"[{\"item_id\":1,\"qty\":1}]","payment_method":5}'
# 2e fois → 200 + Idempotency-Replayed: true, même body
```

### 7.4 Doc `docs/IDEMPOTENCY.md`
- Section "Pourquoi" (réf F-VERIFY-09-02)
- Table routes opt-in
- Format header (regex)
- Comportements 200 / replay / 409 / 422 / 425 / 503
- TTL, scope `(branch_id, user_id, key)`, garanties cross-branch
- "Coexistence avec idempotence applicative" (defense-in-depth)
- Lien vers sentinel comme contrat exécutable

---

## 8. Risques + rollback

### 8.1 Risques

| Risque | Sévérité | Mitigation |
|---|---|---|
| Faux 422 si client tiers (terminal CB physique) ne génère pas header | **P0** | Frontend FoodKing GÉNÈRE déjà la clé. Coordonner avec équipe borne Windows avant `enabled=true` prod. |
| Race window 1.5s bloque double-clic légitime | P2 | `race_wait_ms` configurable (réduire à 500ms si plaintes). |
| Redis indisponible → 503 sur tous POS create/payment | **P0** prod | `fail_open` configurable. Monitoring Sentry + dashboard Redis. Fallback runbook : `IDEMPOTENCY_MIDDLEWARE_ENABLED=false` env hot-reload. |
| Replay-cache stocke headers stale (`set-cookie`, correlation-id) | P1 | `safeHeaders()` strip explicite + tests. |
| Replay d'un 5xx caché | P1 | `complete()` appelé que pour 2xx. `release()` sinon. |
| Conflit 409 sur retry avec timestamp client variable | P2 | Doc IDEMPOTENCY.md : clé stable POUR UN ORDRE LOGIQUE. |

### 8.2 Plan de rollback (3 niveaux)

**Niveau 1** — Disable middleware (instant)
```bash
IDEMPOTENCY_MIDDLEWARE_ENABLED=false
php artisan config:clear
```

**Niveau 2** — Fail-open (Redis instable)
```bash
IDEMPOTENCY_FAIL_OPEN=true
php artisan config:clear
```

**Niveau 3** — Revert git
```bash
git revert <commit-sha>
```

### 8.3 Plan de rollout

1. **S** : merge avec flag false. Sentinels green.
2. **S+1** : staging. Test double-submit cashier réel + borne CB Windows. Latence < 5ms p99.
3. **S+2** : 1 branche pilote prod (ex Châtelet) avec flag true. Surveillance 7j (zéro 503 hors incident, zéro 422 inattendu, replay rate visible).
4. **S+3** : roll-out global prod si métriques OK.

---

## 9. Suivi du finding

À la clôture :
- `docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md` ligne 139 : 🔴 → ✅ RESOLVED
- Réf commit + sentinel `IdempotencyMiddlewareSentinel`
- F-VERIFY-09-02 retiré de la liste P0 frozen-zone ouverte

---

**Fin du plan. ~620 lignes. Exécutable par Cursor/Codex sans question.**
