<?php

namespace App\Providers;

use App\Domain\Delivery\PlatformAdapterRegistry;
use App\Services\Delivery\Adapters\DelicityAdapter;
use App\Services\Delivery\Adapters\DeliverooAdapter;
use App\Services\Delivery\Adapters\UberEatsAdapter;
use Illuminate\Support\ServiceProvider;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Wires the PlatformAdapterRegistry singleton + binds the three
 * platform adapters (Uber Eats, Deliveroo, Delicity) into the
 * Laravel container.
 *
 * Lazy-binding contract:
 *   - The registry stores CLASS NAMES, not adapter instances.
 *   - Adapters are resolved via app()->make() only on first
 *     `for($platform)` call.
 *   - This is required because the Phase-1 stubs throw
 *     LogicException from every method; instantiating them at
 *     boot would still be safe (no method is called), but we
 *     keep the indirection so a future eager-validation pass
 *     can probe the registry without triggering construction
 *     side-effects.
 */
class DeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformAdapterRegistry::class, function () {
            $registry = new PlatformAdapterRegistry();

            // Phase 1: register the three known platforms by class
            // name. The adapter class itself is bound to the
            // container as a default class binding (Laravel resolves
            // it on demand), so no explicit ->bind() is required for
            // the stubs.
            $registry->register('uber_eats', UberEatsAdapter::class);
            $registry->register('deliveroo', DeliverooAdapter::class);
            $registry->register('delicity',  DelicityAdapter::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        // Nothing to boot in Phase 1 — routes / commands /
        // observers will be wired in later phases.
    }
}
