<?php

namespace Tests\Feature\KioskPhase1;

use App\Events\ItemAvailabilityChanged;
use App\Listeners\InvalidateKioskMenuCacheOnItemAvailabilityChanged;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Kiosk Phase 9.1.4 — Listener InvalidateKioskMenuCacheOnItemAvailabilityChanged.
 *
 * Gate: un flip d'availability doit invalider la clé Cache
 * `kiosk.menu.branch.{id}` sans attendre le TTL.
 */
class InvalidateKioskMenuCacheListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_scoped_event_invalidates_only_that_branch_key(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        Cache::put('kiosk.menu.branch.' . $branchA->id, ['stale-A'], 120);
        Cache::put('kiosk.menu.branch.' . $branchB->id, ['stale-B'], 120);

        $event = ItemAvailabilityChanged::forBranch(
            itemId: 42,
            branchId: (int) $branchA->id,
            isAvailable: false,
            reason: 'stock_rupture',
        );

        (new InvalidateKioskMenuCacheOnItemAvailabilityChanged())->handle($event);

        $this->assertFalse(
            Cache::has('kiosk.menu.branch.' . $branchA->id),
            'Le cache de la branche ciblée doit être invalidé.'
        );
        $this->assertTrue(
            Cache::has('kiosk.menu.branch.' . $branchB->id),
            'Le cache des autres branches ne doit PAS être affecté.'
        );
    }

    public function test_global_event_invalidates_all_active_branches(): void
    {
        $branches = Branch::factory()->count(3)->create();
        foreach ($branches as $branch) {
            Cache::put('kiosk.menu.branch.' . $branch->id, ['stale'], 120);
        }

        $item = new \App\Models\Item(['id' => 77, 'status' => 1, 'price' => 5.5]);
        $item->id = 77;
        $event = ItemAvailabilityChanged::fromItem($item);

        (new InvalidateKioskMenuCacheOnItemAvailabilityChanged())->handle($event);

        foreach ($branches as $branch) {
            $this->assertFalse(
                Cache::has('kiosk.menu.branch.' . $branch->id),
                "Cache de la branche {$branch->id} aurait dû être invalidé (event global)."
            );
        }
    }

    public function test_listener_is_registered_for_item_availability_changed_event(): void
    {
        $registered = app(\Illuminate\Contracts\Events\Dispatcher::class)
            ->getListeners(ItemAvailabilityChanged::class);

        $hit = false;
        foreach ($registered as $listener) {
            // Laravel wraps closures — on utilise Reflection pour comparer le
            // nom cible sans dépendre de la représentation interne.
            $closure = \Closure::fromCallable($listener);
            $reflect = new \ReflectionFunction($closure);
            $used = $reflect->getStaticVariables();
            $target = is_array($used['listener'] ?? null) ? ($used['listener'][0] ?? null) : ($used['listener'] ?? null);
            if (is_string($target) && str_contains($target, 'InvalidateKioskMenuCacheOnItemAvailabilityChanged')) {
                $hit = true;
                break;
            }
        }

        $this->assertTrue(
            $hit,
            'Le listener InvalidateKioskMenuCacheOnItemAvailabilityChanged doit être enregistré dans EventServiceProvider.'
        );
    }
}
