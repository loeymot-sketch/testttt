<?php

namespace Tests\Unit\Listeners;

use App\Events\CatalogChanged;
use App\Events\ItemAvailabilityChanged;
use App\Events\ItemExtraAvailabilityChanged;
use App\Events\ItemVariationAvailabilityChanged;
use App\Listeners\InvalidateKioskMenuCacheOnCatalogChange;
use App\Listeners\InvalidateKioskMenuCacheOnItemAvailabilityChanged;
use App\Providers\EventServiceProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Sentinel — every catalog-mutating event MUST be wired to a kiosk-cache
 * invalidator inside {@see EventServiceProvider::$listen}.
 *
 * Source: owner Q9-S1 (Q2 = fix) 2026-05-21. Without this invariant the
 * kiosk backend menu cache (`kiosk.menu.branch.{id}`, TTL 60s, served by
 * `App\Http\Controllers\Frontend\MenuController::index`) returns STALE data
 * for up to 60 seconds after an admin toggles a sauce / topping / variation
 * availability — chef runs out of Algérienne → admin disables → customer
 * still sees it on the kiosk → adds to cart → rejected at confirm.
 *
 * Why read `$listen` directly via reflection rather than the dispatcher:
 *   Laravel's dispatcher wraps each listener target in a closure, so
 *   `Event::getListeners($event)` returns Closures whose underlying class
 *   name has to be excavated via ReflectionFunction + static variables
 *   (see {@see \Tests\Feature\KioskPhase1\InvalidateKioskMenuCacheListenerTest::test_listener_is_registered_for_item_availability_changed_event}).
 *   That works but is brittle. The `$listen` array is the source of truth
 *   the provider hands to Laravel — comparing against it is exact and
 *   resilient across framework upgrades.
 *
 * Append-only: never modify or weaken the expected map.
 */
class KioskCacheInvalidationWiringSentinelTest extends TestCase
{
    public function test_every_catalog_event_is_wired_to_kiosk_cache_invalidator(): void
    {
        $expected = [
            ItemAvailabilityChanged::class          => InvalidateKioskMenuCacheOnItemAvailabilityChanged::class,
            ItemExtraAvailabilityChanged::class     => InvalidateKioskMenuCacheOnCatalogChange::class,
            ItemVariationAvailabilityChanged::class => InvalidateKioskMenuCacheOnCatalogChange::class,
        ];

        $reflection = new ReflectionClass(EventServiceProvider::class);
        $property = $reflection->getProperty('listen');
        $property->setAccessible(true);
        $listen = $property->getValue($reflection->newInstanceWithoutConstructor());

        foreach ($expected as $event => $listener) {
            $this->assertArrayHasKey(
                $event,
                $listen,
                sprintf(
                    'Sentinel breach: event %s is missing from EventServiceProvider::$listen entirely.',
                    $event
                )
            );
            $this->assertContains(
                $listener,
                $listen[$event],
                sprintf(
                    'Sentinel breach: %s must wire %s. Without it, the kiosk backend cache (TTL 60s) returns stale data after an admin toggle. See Q9-S1 owner fix 2026-05-21.',
                    $event,
                    $listener
                )
            );
        }
    }
}
