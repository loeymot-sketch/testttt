<?php

namespace App\Http\Middleware;

use App\Exceptions\MissingIdempotencyKeyException;
use App\Services\Idempotency\IdempotencyKeyRepository;
use App\Services\Idempotency\IdempotencyRecord;
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
 * `Idempotency-Replayed: true`. Missing header on a required route → 422.
 *
 * Compat: app-level UNIQUE (branch_id, idempotency_key) and
 * `OrderService::findExistingOrderForIdempotencyRecovery` remain as defense-
 * in-depth (cf. PLAN_P11 §1.2).
 */
final class IdempotencyKeyMiddleware
{
    public const HEADER          = 'X-Idempotency-Key';
    public const REPLAY_HEADER   = 'Idempotency-Replayed';
    public const STORED_AT_HEADER = 'Idempotency-Stored-At';
    public const CONFLICT_HEADER = 'Idempotency-Key-Conflict';

    public function __construct(
        private readonly IdempotencyKeyRepository $repository,
    ) {
    }

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! config('idempotency.enabled', false)) {
            return $next($request);
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key        = trim((string) $request->header(self::HEADER));
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
        $userId   = (int) ($request->user()?->id ?? 0);

        if ($branchId < 0 || $userId <= 0) {
            throw new MissingIdempotencyKeyException(
                'Idempotency requires authenticated user with resolvable branch_id.'
            );
        }

        $payloadHash = hash('sha256', $request->getContent() ?: '');
        $scopedKey   = sprintf(
            'idempotency:v1:%d:%d:%s',
            $branchId,
            $userId,
            hash('sha256', $key)
        );

        try {
            // Phase 1 — try replay first
            $replay = $this->repository->find($scopedKey);
            if ($replay !== null) {
                if ($replay->payloadHash !== $payloadHash) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Idempotency key reused with different payload.',
                        'code'    => 'IDEMPOTENCY_KEY_CONFLICT',
                    ], 409, [self::CONFLICT_HEADER => 'true']);
                }
                return $this->rebuildReplayResponse($replay);
            }

            // Phase 2 — atomic acquire (SET NX EX equivalent)
            $acquired = $this->repository->acquire(
                $scopedKey,
                $payloadHash,
                (int) config('idempotency.ttl_seconds', 86400)
            );
            if (! $acquired) {
                $replay = $this->repository->waitForCompletion(
                    $scopedKey,
                    (int) config('idempotency.race_wait_ms', 1500)
                );
                if ($replay !== null) {
                    if ($replay->payloadHash !== $payloadHash) {
                        return new JsonResponse([
                            'success' => false,
                            'message' => 'Idempotency key reused with different payload.',
                            'code'    => 'IDEMPOTENCY_KEY_CONFLICT',
                        ], 409, [self::CONFLICT_HEADER => 'true']);
                    }
                    return $this->rebuildReplayResponse($replay);
                }
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Idempotent request already in flight; retry shortly.',
                    'code'    => 'IDEMPOTENCY_IN_FLIGHT',
                ], 425);
            }
        } catch (IdempotencyStorageUnavailableException $e) {
            Log::warning('[IdempotencyMiddleware] storage unavailable', ['error' => $e->getMessage()]);
            if (config('idempotency.fail_open', false)) {
                return $next($request); // bypass — relies on app-layer UNIQUE
            }
            return new JsonResponse([
                'success' => false,
                'message' => 'Idempotency storage unavailable.',
                'code'    => 'IDEMPOTENCY_STORAGE_UNAVAILABLE',
            ], 503);
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
                $scopedKey,
                $response,
                $payloadHash,
                (int) config('idempotency.ttl_seconds', 86400)
            );
        } else {
            $this->repository->release($scopedKey);
        }

        return $response;
    }

    private function isRouteRequired(Request $request): bool
    {
        $route = $request->route();
        $name  = $route?->getName() ?? '';
        $required = (array) config('idempotency.required_routes', []);

        foreach ($required as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            if (str_starts_with($pattern, 'name:')) {
                if ($name === substr($pattern, 5)) {
                    return true;
                }
                continue;
            }
            if ($request->is(ltrim($pattern, '/'))) {
                return true;
            }
        }
        return false;
    }

    private function resolveBranchId(Request $request): int
    {
        $user           = $request->user();
        $authBranchId   = (int) ($user?->branch_id ?? -1);

        // Admin global (branch_id=0) → may operate on any branch via payload.
        if ($authBranchId === 0
            && $user
            && method_exists($user, 'hasRole')
            && $user->hasRole('Admin')
        ) {
            $payloadBranchId = (int) $request->input('branch_id', 0);
            return $payloadBranchId > 0 ? $payloadBranchId : 0;
        }

        if ($authBranchId > 0) {
            return $authBranchId;
        }

        // [P11 fix-resolve-kiosk] Kiosk users have branch_id=0 on the User row but
        // a KioskMachine pivot rows the actual branch. Resolve via the pivot so
        // /api/frontend/order/{order}/payment-confirm picks up the correct scope.
        if ($user && $authBranchId === 0) {
            try {
                if (class_exists(\App\Models\KioskMachine::class)) {
                    $kioskBranchId = (int) (\App\Models\KioskMachine::query()
                        ->where('user_id', $user->id)
                        ->value('branch_id') ?? 0);
                    if ($kioskBranchId > 0) {
                        return $kioskBranchId;
                    }
                }
            } catch (\Throwable) {
                // Defensive: fall through to payload lookup
            }
        }

        return (int) $request->input('branch_id', -1);
    }

    private function rebuildReplayResponse(IdempotencyRecord $rec): SymfonyResponse
    {
        $body = base64_decode($rec->bodyBase64, true);
        if ($body === false) {
            $body = '';
        }

        // Flatten arrays of single values for headers compatibility with Response.
        $headers = [];
        foreach ($rec->headers as $name => $values) {
            if (is_array($values)) {
                $headers[$name] = count($values) === 1 ? $values[0] : $values;
            } else {
                $headers[$name] = $values;
            }
        }

        $response = new Response($body, $rec->status, $headers);
        $response->headers->set(self::REPLAY_HEADER, 'true');
        $response->headers->set(self::STORED_AT_HEADER, $rec->createdAt);
        return $response;
    }
}
