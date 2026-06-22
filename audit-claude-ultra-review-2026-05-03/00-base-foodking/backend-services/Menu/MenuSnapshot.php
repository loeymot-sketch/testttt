<?php

namespace App\Services\Menu;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Branch-scoped monotonic snapshot version for menu projections.
 *
 * Clients (POS / Kiosk / Web) poll `GET /api/admin/menu-projection` and store
 * the returned `snapshot_version`. When they reconnect after a WS drop, they
 * compare the persisted value to the server’s current value and re-fetch only
 * when they diverge — cheaper than re-hashing the full payload.
 *
 * Storage: cache (Redis in prod, array in tests). Atomic INCR ensures that two
 * concurrent availability toggles cannot collide; absent key initializes to 1.
 *
 * @see app/Services/Menu/MenuProjectionService.php
 * @see app/Listeners/BumpMenuSnapshotOnItemAvailabilityChanged.php
 */
final class MenuSnapshot
{
    private const TTL_DAYS = 7;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    public static function make(): self
    {
        return new self(Cache::store());
    }

    public function current(int $branchId): int
    {
        $key = $this->key($branchId);
        $raw = $this->cache->get($key);
        if ($raw === null || $raw === false) {
            $this->cache->put($key, 1, now()->addDays(self::TTL_DAYS));
            return 1;
        }

        return (int) $raw;
    }

    /**
     * Atomic bump. Returns the new version. Used by listeners on availability
     * / menu mutations.
     */
    public function bump(int $branchId): int
    {
        $key = $this->key($branchId);

        // Initialize to 1 first if missing, then INCR to 2; otherwise plain INCR.
        if ($this->cache->get($key) === null) {
            $this->cache->put($key, 1, now()->addDays(self::TTL_DAYS));
        }

        // Fallback for cache drivers that do not implement increment atomically.
        if (method_exists($this->cache, 'increment')) {
            $next = $this->cache->increment($key);
            if (is_int($next) || is_string($next)) {
                return (int) $next;
            }
        }

        $next = $this->current($branchId) + 1;
        $this->cache->put($key, $next, now()->addDays(self::TTL_DAYS));

        return $next;
    }

    private function key(int $branchId): string
    {
        return "menu:snapshot_version:branch:{$branchId}";
    }
}
