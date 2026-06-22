<?php

namespace Tests\Feature\Stock;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Events\ItemAvailabilityChanged;
use App\Events\StockLevelChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\Tax;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Sentinel — CV1-WIZARD-COMPOSABLE-001 / T-WC-STOCK-PROPAGATION-01.
 *
 * Verrouille la propagation rupture stock pour les options du wizard
 * (variations / extras / addons) en exerçant le vrai chemin
 * {@see StockService::decrementForOrder()} (pas un dispatch manuel).
 *
 * Asymétrie documentée (audit Axe A.2 §4 path B + #4 risque) :
 *
 *  | Path                         | stockable_type    | line_uid contient   | IBA auto-sync ? | ItemAvailabilityChanged | StockLevelChanged |
 *  |------------------------------|-------------------|---------------------|-----------------|-------------------------|-------------------|
 *  | Variation (option wizard)    | ItemVariation     | :variation:         | NON             | NON                     | OUI               |
 *  | Extra (option wizard)        | ItemExtra         | :extra:             | NON             | NON                     | OUI               |
 *  | Addon item (composition)     | Item              | :addon-item:        | OUI             | OUI                     | OUI               |
 *  | Item direct (ligne classique)| Item              | :item:              | OUI             | OUI                     | NON               |
 *
 * Conséquence kiosk runtime :
 *  - Variation/extra rupture → fan-out via StockLevelChanged → CatalogChanged
 *    → invalidation `kiosk.menu.branch.{id}` + refetch complet.
 *  - Addon rupture → IBA flippé + ItemAvailabilityChanged sur canal branch
 *    + StockLevelChanged → CatalogChanged (double propagation).
 *
 * Sources :
 *  - reports/audit/CV1_WIZARD_MASTER_SYNTHESIS_2026-05-02.md §4 (Phase D)
 *  - {@see StockService::syncItemAvailabilityForStockLevel()}    (skip si stockable_type !== Item)
 *  - {@see StockService::isChoiceBoundaryMutation()}             (true sur variation/extra OU addon-item)
 *
 * Invariants protégés : #4 dispatch-after-commit (déjà couvert
 * {@see StockAvailabilityAfterCommitTest}) + cohérence cross-surface
 * POS/Kiosk après rupture option (T-WC-STOCK-SYNC-01 plan Phase D.3).
 */
class WizardOptionStockSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_variation_rupture_emits_stock_level_changed_without_item_availability_changed(): void
    {
        $variationId = 9001;
        [$branch, $order] = $this->orderWithVariationStock($variationId, onHand: 1);

        Event::fake([StockLevelChanged::class, ItemAvailabilityChanged::class]);

        app(StockService::class)->decrementForOrder($order);

        Event::assertDispatched(
            StockLevelChanged::class,
            function (StockLevelChanged $event) use ($branch): bool {
                return $event->branchId === (int) $branch->id
                    && $event->stockLevelIds !== [];
            },
            'StockLevelChanged must fire when an ItemVariation stock crosses 0 — '
            . 'this drives CatalogChanged → kiosk cache invalidation per audit Axe A.2 §4.'
        );
        Event::assertNotDispatched(
            ItemAvailabilityChanged::class,
            'ItemAvailabilityChanged must NOT fire for ItemVariation rupture — '
            . 'syncItemAvailabilityForStockLevel skips when stockable_type !== Item::class.'
        );

        $this->assertSame(0, (int) StockLevel::query()
            ->where('branch_id', $branch->id)
            ->where('stockable_type', ItemVariation::class)
            ->where('stockable_id', $variationId)
            ->value('on_hand'));
    }

    public function test_extra_rupture_emits_stock_level_changed_without_item_availability_changed(): void
    {
        $extraId = 8001;
        [$branch, $order] = $this->orderWithExtraStock($extraId, onHand: 1);

        Event::fake([StockLevelChanged::class, ItemAvailabilityChanged::class]);

        app(StockService::class)->decrementForOrder($order);

        Event::assertDispatched(
            StockLevelChanged::class,
            function (StockLevelChanged $event) use ($branch): bool {
                return $event->branchId === (int) $branch->id
                    && $event->stockLevelIds !== [];
            },
            'StockLevelChanged must fire when an ItemExtra stock crosses 0 (same boundary path as variation).'
        );
        Event::assertNotDispatched(
            ItemAvailabilityChanged::class,
            'ItemAvailabilityChanged must NOT fire for ItemExtra rupture — symmetric with variation path.'
        );

        $this->assertSame(0, (int) StockLevel::query()
            ->where('branch_id', $branch->id)
            ->where('stockable_type', ItemExtra::class)
            ->where('stockable_id', $extraId)
            ->value('on_hand'));
    }

    public function test_addon_item_rupture_emits_both_events_and_flips_item_branch_availability(): void
    {
        [$branch, $order, $addonItem] = $this->orderWithAddonStock(onHand: 1);

        Event::fake([StockLevelChanged::class, ItemAvailabilityChanged::class]);

        app(StockService::class)->decrementForOrder($order);

        Event::assertDispatched(
            ItemAvailabilityChanged::class,
            function (ItemAvailabilityChanged $event) use ($branch, $addonItem): bool {
                return $event->itemId === (int) $addonItem->id
                    && $event->branchId === (int) $branch->id
                    && $event->isAvailable === false
                    && $event->reason === 'stock_rupture';
            },
            'Addon items go through stockable_type=Item, so syncItemAvailabilityForStockLevel '
            . 'auto-flips the IBA row and emits ItemAvailabilityChanged on the branch channel.'
        );
        Event::assertDispatched(
            StockLevelChanged::class,
            function (StockLevelChanged $event) use ($branch): bool {
                return $event->branchId === (int) $branch->id
                    && $event->stockLevelIds !== [];
            },
            'Addon rupture also fires StockLevelChanged because line_uid contains :addon-item: '
            . '(isChoiceBoundaryMutation returns true even for stockable_type=Item).'
        );

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $addonItem->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
        ]);
    }

    public function test_documents_asymmetry_variation_path_does_not_create_item_branch_availability(): void
    {
        $variationId = 9002;
        [$branch, $order] = $this->orderWithVariationStock($variationId, onHand: 1);

        app(StockService::class)->decrementForOrder($order);

        $this->assertNull(
            ItemBranchAvailability::query()
                ->where('item_id', $variationId)
                ->where('branch_id', $branch->id)
                ->first(),
            'Documented asymmetry (audit Axe A.2 §4 path B): variation rupture never writes to '
            . 'item_branch_availability because IBA is keyed on item_id, not variation_id. '
            . 'Kiosk relies on StockLevelChanged → CatalogChanged → kiosk.menu.branch.{id} '
            . 'invalidation + refetch to surface the rupture. If this assertion ever flips to '
            . 'non-null, the propagation contract changed and ChoiceAvailabilityResolver must '
            . 'be reviewed (T-WC-STOCK-SYNC-01 plan Phase D.3).'
        );

        $this->assertSame(0, (int) StockLevel::query()
            ->where('branch_id', $branch->id)
            ->where('stockable_type', ItemVariation::class)
            ->where('stockable_id', $variationId)
            ->value('on_hand'));
    }

    /**
     * Build an order whose only stocked option is a synthetic ItemVariation row.
     * The parent Item has no StockLevel, so its requirement is skipped — only
     * the variation requirement decrements, isolating the variation path.
     *
     * @return array{0: Branch, 1: Order}
     */
    private function orderWithVariationStock(int $variationId, int $onHand): array
    {
        [$branch, $item, $order] = $this->baseFixture();

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 10,
            'discount' => 0,
            'total_price' => 10,
            'item_variations' => json_encode([['id' => $variationId, 'quantity' => 1]]),
            'item_extras' => json_encode([]),
        ]);

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $variationId,
            'on_hand' => $onHand,
            'reserved' => 0,
        ]);

        return [$branch, $order];
    }

    /**
     * Build an order whose only stocked option is a synthetic ItemExtra row.
     *
     * @return array{0: Branch, 1: Order}
     */
    private function orderWithExtraStock(int $extraId, int $onHand): array
    {
        [$branch, $item, $order] = $this->baseFixture();

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 10,
            'discount' => 0,
            'total_price' => 10,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([['id' => $extraId, 'quantity' => 1]]),
        ]);

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extraId,
            'on_hand' => $onHand,
            'reserved' => 0,
        ]);

        return [$branch, $order];
    }

    /**
     * Build an order whose only stocked option is an addon Item, surfaced via
     * `composition_snapshot.addons[*].addon_item_id` (real wizard payload shape).
     *
     * @return array{0: Branch, 1: Order, 2: Item}
     */
    private function orderWithAddonStock(int $onHand): array
    {
        [$branch, $item, $order, $tax, $category] = $this->baseFixture(returnTaxAndCategory: true);

        $addonItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 2.50,
            'status' => Status::ACTIVE,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 10,
            'discount' => 0,
            'total_price' => 12.50,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
            'composition_snapshot' => [
                'addons' => [
                    ['addon_item_id' => $addonItem->id, 'quantity' => 1],
                ],
            ],
        ]);

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => Item::class,
            'stockable_id' => $addonItem->id,
            'on_hand' => $onHand,
            'reserved' => 0,
        ]);

        return [$branch, $order, $addonItem];
    }

    /**
     * Common fixture: branch + tax + category + item + order, no StockLevel
     * for the parent Item (skipped by mutateForOrderInTransaction).
     *
     * @return array{0: Branch, 1: Item, 2: Order}|array{0: Branch, 1: Item, 2: Order, 3: Tax, 4: ItemCategory}
     */
    private function baseFixture(bool $returnTaxAndCategory = false): array
    {
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);
        $order = Order::factory()->create(['branch_id' => $branch->id]);

        return $returnTaxAndCategory
            ? [$branch, $item, $order, $tax, $category]
            : [$branch, $item, $order];
    }
}
