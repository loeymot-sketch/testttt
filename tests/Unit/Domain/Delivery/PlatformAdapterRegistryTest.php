<?php

namespace Tests\Unit\Domain\Delivery;

use App\Domain\Delivery\PlatformAdapter;
use App\Domain\Delivery\PlatformAdapterRegistry;
use App\Services\Delivery\Adapters\DelicityAdapter;
use App\Services\Delivery\Adapters\DeliverooAdapter;
use App\Services\Delivery\Adapters\UberEatsAdapter;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Verifies the registry binding contract:
 *   - DeliveryServiceProvider has registered all three platforms
 *     by the time the Laravel app boots (this is the test app
 *     instance — extends Tests\TestCase to use it);
 *   - for($platform) resolves a PlatformAdapter instance via the
 *     container;
 *   - unknown platforms throw InvalidArgumentException.
 */
class PlatformAdapterRegistryTest extends TestCase
{
    public function test_registry_is_bound_as_singleton(): void
    {
        $a = app(PlatformAdapterRegistry::class);
        $b = app(PlatformAdapterRegistry::class);

        $this->assertInstanceOf(PlatformAdapterRegistry::class, $a);
        $this->assertSame($a, $b, 'Registry must be a singleton.');
    }

    public function test_three_known_platforms_are_registered_at_boot(): void
    {
        /** @var PlatformAdapterRegistry $registry */
        $registry = app(PlatformAdapterRegistry::class);

        $this->assertEqualsCanonicalizing(
            ['uber_eats', 'deliveroo', 'delicity'],
            $registry->platforms()
        );
    }

    public function test_for_resolves_correct_adapter_per_platform(): void
    {
        /** @var PlatformAdapterRegistry $registry */
        $registry = app(PlatformAdapterRegistry::class);

        $this->assertInstanceOf(UberEatsAdapter::class, $registry->for('uber_eats'));
        $this->assertInstanceOf(DeliverooAdapter::class, $registry->for('deliveroo'));
        $this->assertInstanceOf(DelicityAdapter::class, $registry->for('delicity'));

        // All implement the contract.
        $this->assertInstanceOf(PlatformAdapter::class, $registry->for('uber_eats'));
    }

    public function test_for_throws_on_unknown_platform(): void
    {
        /** @var PlatformAdapterRegistry $registry */
        $registry = app(PlatformAdapterRegistry::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/just_eat/');
        $registry->for('just_eat');
    }

    public function test_register_overrides_existing_binding(): void
    {
        $registry = new PlatformAdapterRegistry();
        $registry->register('uber_eats', UberEatsAdapter::class);
        $this->assertInstanceOf(UberEatsAdapter::class, $registry->for('uber_eats'));

        // Re-register with a different class (Deliveroo, just to
        // observe the override; both are valid PlatformAdapter
        // implementations).
        $registry->register('uber_eats', DeliverooAdapter::class);
        $this->assertInstanceOf(DeliverooAdapter::class, $registry->for('uber_eats'));
    }
}
