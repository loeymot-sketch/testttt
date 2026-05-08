<?php

namespace App\Domain\Delivery;

use InvalidArgumentException;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Singleton registry mapping the platform-key strings used in
 * the database (`delivery_platforms.platform` enum) to their
 * concrete PlatformAdapter implementation.
 *
 * Registered by DeliveryServiceProvider at boot. The registry
 * stores Laravel-container BINDINGS (class names), not adapter
 * instances, so the actual adapter is built lazily on first
 * `for()` call. This is important: the Phase-1 stubs throw
 * LogicException from every method, but the provider bind
 * itself must be safe to run at boot.
 */
class PlatformAdapterRegistry
{
    /**
     * @var array<string, class-string<PlatformAdapter>>
     */
    private array $bindings = [];

    /**
     * Register a platform adapter binding. Idempotent —
     * re-registering the same key replaces the previous binding.
     *
     * @param  class-string<PlatformAdapter>  $adapter
     */
    public function register(string $platform, string $adapter): void
    {
        $this->bindings[$platform] = $adapter;
    }

    /**
     * Resolve an adapter for a given platform key. Throws
     * InvalidArgumentException if the platform is not registered
     * — fail loud so that a typo never silently routes through
     * a default.
     */
    public function for(string $platform): PlatformAdapter
    {
        if (!isset($this->bindings[$platform])) {
            throw new InvalidArgumentException(
                "No PlatformAdapter registered for platform [{$platform}]."
            );
        }

        return app()->make($this->bindings[$platform]);
    }

    /**
     * Test / introspection helper — list of registered platform
     * keys. Used by the Phase-1 unit tests.
     *
     * @return array<int,string>
     */
    public function platforms(): array
    {
        return array_keys($this->bindings);
    }
}
