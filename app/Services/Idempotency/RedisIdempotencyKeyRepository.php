<?php

namespace App\Services\Idempotency;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * F-VERIFY-09-02 — Cache-backed idempotency repository.
 *
 * Despite the historical class name (kept for plan/SemVer alignment), this
 * implementation uses Laravel's Cache abstraction (`Cache::store($conn)`),
 * which delegates to:
 *   - `predis`/`phpredis` `SET NX EX` in production (`redis` driver),
 *   - in-memory store in unit tests (`array` driver),
 *   - file lock + ttl on a single host (`file` driver).
 *
 * `Cache::add()` is documented to return `true` only if the key did not
 * exist — atomic on every supported driver — which matches the contract
 * required by `IdempotencyKeyRepository::acquire()`.
 *
 * Rationale (deviation from PLAN_P11 §3.4 raw `Redis::connection()`):
 *  1) feature flag default OFF requires test coverage with `array` cache;
 *  2) production keeps Redis NX semantics via the redis cache driver;
 *  3) a single repository implementation = simpler service container wiring.
 */
final class RedisIdempotencyKeyRepository implements IdempotencyKeyRepository
{
    public function __construct(private readonly ?string $cacheStore = null)
    {
    }

    public function find(string $scopedKey): ?IdempotencyRecord
    {
        try {
            $raw = $this->store()->get($scopedKey);
        } catch (\Throwable $e) {
            throw new IdempotencyStorageUnavailableException($e->getMessage(), 0, $e);
        }

        if ($raw === null || $raw === false) {
            return null;
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $record = IdempotencyRecord::fromArray($decoded);
        return $record->state === 'COMPLETED' ? $record : null;
    }

    public function acquire(string $scopedKey, string $payloadHash, int $ttlSeconds): bool
    {
        $placeholder = (new IdempotencyRecord(
            status:      0,
            headers:     [],
            bodyBase64:  '',
            payloadHash: $payloadHash,
            createdAt:   now()->toIso8601String(),
            state:       'PENDING',
        ))->toArray();

        // [Phase F.6 finding F-6-6-FIND-04 P2 / 2026-05-23]
        // PENDING placeholder uses a dedicated short TTL (default 30s) decoupled
        // from the caller-supplied $ttlSeconds (24h for COMPLETED). Rationale:
        // SIGKILL / PHP-FPM restart between Phase-2 acquire() and Phase-3
        // release() must NOT trap the key for 24h returning 425
        // IDEMPOTENCY_IN_FLIGHT to legitimate retries. The COMPLETED record
        // written by complete() still honors $ttlSeconds (24h).
        // Source: config/idempotency.php `pending_ttl_seconds`.
        $pendingTtl = (int) config('idempotency.pending_ttl_seconds', 30);

        try {
            // Cache::add() is atomic across all drivers: returns true only
            // when the key was created. Equivalent to `SET key val NX EX ttl`
            // when the redis driver is in use.
            return (bool) $this->store()->add($scopedKey, $placeholder, $pendingTtl);
        } catch (\Throwable $e) {
            throw new IdempotencyStorageUnavailableException($e->getMessage(), 0, $e);
        }
    }

    public function complete(string $scopedKey, SymfonyResponse $response, string $payloadHash, int $ttlSeconds): void
    {
        $record = new IdempotencyRecord(
            status:      $response->getStatusCode(),
            headers:     $this->safeHeaders($response),
            bodyBase64:  base64_encode((string) $response->getContent()),
            payloadHash: $payloadHash,
            createdAt:   now()->toIso8601String(),
            state:       'COMPLETED',
        );

        try {
            $this->store()->put($scopedKey, $record->toArray(), $ttlSeconds);
        } catch (\Throwable $e) {
            Log::warning('[Idempotency] complete() failed', [
                'key' => $scopedKey,
                'err' => $e->getMessage(),
            ]);
        }
    }

    public function release(string $scopedKey): void
    {
        try {
            $this->store()->forget($scopedKey);
        } catch (\Throwable) {
            // Silently swallow: the placeholder will simply expire after TTL.
        }
    }

    public function waitForCompletion(string $scopedKey, int $waitMs): ?IdempotencyRecord
    {
        $deadline = microtime(true) + ($waitMs / 1000);
        do {
            try {
                $rec = $this->find($scopedKey);
            } catch (IdempotencyStorageUnavailableException) {
                return null;
            }
            if ($rec !== null) {
                return $rec;
            }
            usleep(50_000); // 50ms
        } while (microtime(true) < $deadline);

        return null;
    }

    private function store(): CacheRepository
    {
        return $this->cacheStore !== null
            ? Cache::store($this->cacheStore)
            : Cache::store();
    }

    /**
     * Filter response headers we should not replay verbatim. Keeps body
     * deterministic across replays and avoids stale Set-Cookie / correlation
     * IDs from leaking back to the second caller.
     */
    private function safeHeaders(SymfonyResponse $response): array
    {
        $strip = ['set-cookie', 'date', 'x-correlation-id', 'x-request-id'];
        $out = [];
        foreach ($response->headers->all() as $name => $values) {
            if (in_array(strtolower($name), $strip, true)) {
                continue;
            }
            $out[$name] = is_array($values) ? $values : [$values];
        }

        // Always preserve content-type so JsonResponse semantics survive replay.
        $contentType = $response->headers->get('content-type');
        if ($contentType) {
            $out['Content-Type'] = [$contentType];
        } elseif (! isset($out['Content-Type'])) {
            $out['Content-Type'] = ['application/json'];
        }

        return $out;
    }
}
