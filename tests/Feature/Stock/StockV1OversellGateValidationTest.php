<?php

namespace Tests\Feature\Stock;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * [GOAL_WIZARD_DYNAMIC W6 / Sub 3.2 — empirical stock-gate validation]
 *
 * Locks the V1 Le Cayenne anti-oversell truth verified empirically 2026-06-08
 * (foodking_e2e clone: 0 stock_levels rows + 0 items with max_daily_qty):
 *
 *  - The LIVE anti-oversell gate is the manual-86 `is_available` flag, enforced
 *    PRE-COMMIT by AvailabilityService::assertItemsOrderableForBranch (HTTP 422,
 *    lockForUpdate), multiply called from the FROZEN PricingService SSOT
 *    (every POS/kiosk/online order traverses it) + OrderService/FrontendOrderService.
 *
 *  - The post-commit numeric decrement (StockService::mutateForOrder) SKIPS any
 *    item with no stock_level row (`if (! $level) continue;`). Since V1 seeds no
 *    numeric stock, the StockUnavailableException that DecrementStockOnOrderCreated
 *    isolates (log + StockDecrementFailedEvent, by design after WG-2) is DORMANT —
 *    there is no live silent-oversell path in V1.
 *
 * This test fixes the gate behaviour so a future change that weakens the
 * pre-flight rejection is caught.
 */
class StockV1OversellGateValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_86d_item_is_rejected_pre_commit_with_422(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $branch = Branch::factory()->create();

        // Manual 86 at the branch level (the live V1 rupture mechanism).
        app(AvailabilityService::class)->toggle((int) $item->id, (int) $branch->id, false, 'out_of_stock');

        try {
            app(AvailabilityService::class)
                ->assertItemsOrderableForBranch((int) $branch->id, [(int) $item->id]);
            $this->fail('Expected a 422 rejection for a branch-86d item — the anti-oversell gate did not fire.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('indisponible', $e->getMessage());
        }
    }

    public function test_available_item_passes_the_pre_flight_gate(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $branch = Branch::factory()->create();

        // No branch-86 row → orderable → no throw.
        app(AvailabilityService::class)
            ->assertItemsOrderableForBranch((int) $branch->id, [(int) $item->id]);

        $this->assertTrue(true, 'An available item must pass the pre-flight gate.');
    }

    public function test_inactive_catalog_item_is_rejected_pre_commit_with_422(): void
    {
        $item = Item::factory()->create(['status' => Status::INACTIVE]);
        $branch = Branch::factory()->create();

        try {
            app(AvailabilityService::class)
                ->assertItemsOrderableForBranch((int) $branch->id, [(int) $item->id]);
            $this->fail('Expected a 422 rejection for an inactive catalogue item.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode());
        }
    }
}
