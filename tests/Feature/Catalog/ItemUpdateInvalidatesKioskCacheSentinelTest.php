<?php

namespace Tests\Feature\Catalog;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\ItemUpdated;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [GOAL-I2-HEAL-02 2026-05-24] Phase I.3 RISK-01 AMBER sentinel.
 *
 * Locks the contract that admin rename/reprice of an item invalidates the
 * kiosk menu cache (`kiosk.menu.branch.{id}`) immediately rather than
 * waiting for the 60s TTL. Implementation under sentinel :
 *   1. ItemUpdated event class exists (App\Events\ItemUpdated)
 *   2. EventServiceProvider $listen wires ItemUpdated to
 *      InvalidateKioskMenuCacheOnCatalogChange + PersistCatalogChangedToOutbox
 *   3. CatalogChanged::fromMenuMutation handles ItemUpdated bridge
 *
 * If any of (1)(2)(3) regresses, this test fails.
 *
 * Notes :
 *   - NF525 Pricing SSOT is NOT affected by stale kiosk cache (order POST
 *     recomputes via PricingService). Sentinel covers UX latency only.
 *   - Mirrors symmetry with ItemCreated / ItemDeleted listeners.
 */
class ItemUpdateInvalidatesKioskCacheSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_updated_event_invalidates_kiosk_menu_cache(): void
    {
        Queue::fake();

        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        // Seed stale kiosk menu cache for this branch.
        $cacheKey = "kiosk.menu.branch.{$branch->id}";
        Cache::put($cacheKey, ['stale_payload' => true], 60);
        $this->assertTrue(Cache::has($cacheKey), 'precondition: cache seeded');

        // Fire ItemUpdated event (simulates ItemService::update commit).
        event(new ItemUpdated(itemId: 42));

        // Listener InvalidateKioskMenuCacheOnCatalogChange must have flushed it.
        $this->assertFalse(
            Cache::has($cacheKey),
            'ItemUpdated did not flush kiosk.menu.branch.{id} — RISK-01 regressed'
        );
    }

    public function test_item_updated_event_persists_catalog_changed_outbox_row(): void
    {
        Queue::fake();

        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $snapshot = app(MenuSnapshot::class);
        $before = $snapshot->current($branch->id);

        event(new ItemUpdated(itemId: 99));

        $row = DomainEvent::query()
            ->where('event_type', EventType::CATALOG_CHANGED)
            ->where('aggregate_type', 'item')
            ->where('aggregate_id', 99)
            ->where('branch_id', $branch->id)
            ->firstOrFail();

        $this->assertSame('CatalogChanged', $row->broadcast_as);
        $this->assertSame('item', $row->payload['entity_type']);
        $this->assertSame(99, (int) $row->payload['entity_id']);
        $this->assertSame('updated', $row->payload['change_type']);
        $this->assertSame(['private-branch.' . $branch->id], json_decode($row->channel, true));
        $this->assertGreaterThan($before, $snapshot->current($branch->id));
    }
}
