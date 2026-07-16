<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Events\ItemExtraAvailabilityChanged;
use App\Events\ItemVariationAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [F-016a-BIS] Wrappers AvailabilityService pour extras et variations.
 *
 * Verrouille la sémantique manual rupture polymorphique :
 *  - création / mise à jour de stock_levels avec manual_unavailable_reason
 *  - idempotence (re-toggle même état = no-op)
 *  - dispatch domain event after-commit
 *  - isolation cross-branche (toggle branche A ne touche pas branche B)
 *  - aggregate snapshot pour StockManager UI
 */
class AvailabilityServiceExtrasVariationsTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AvailabilityService::class);
    }

    public function test_toggle_extra_unavailable_creates_stock_level_with_manual_reason(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class]);
        [$branch, $extra] = $this->makeBranchAndExtra();

        $level = $this->service->toggleExtra($extra->id, $branch->id, false, 'seasonal');

        $this->assertSame('seasonal', $level->manual_unavailable_reason);
        $this->assertNotNull($level->manual_unavailable_since);
        $this->assertSame(0, (int) $level->on_hand); // default when row didn't exist
        $this->assertDatabaseHas('stock_levels', [
            'branch_id' => $branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'manual_unavailable_reason' => 'seasonal',
        ]);
    }

    public function test_toggle_extra_available_clears_manual_columns(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class]);
        [$branch, $extra] = $this->makeBranchAndExtra();

        $this->service->toggleExtra($extra->id, $branch->id, false, 'supplier_issue');
        $this->service->toggleExtra($extra->id, $branch->id, true, null);

        // [TERRAIN-HEAL 2026-07-16 · MGMT-86-REACTIVATE] Réactiver un extra flag-managed (on_hand=0,
        // pas de stock réel) SUPPRIME le row → retour à « absent = disponible » PARTOUT (y compris
        // borne). Avant : le row on_hand=0 subsistait et le resolver le relisait comme rupture éternelle.
        $this->assertDatabaseMissing('stock_levels', [
            'branch_id' => $branch->id,
            'stockable_id' => $extra->id,
        ]);
        $this->assertTrue($this->service->isExtraAvailable($extra->id, $branch->id));
    }

    public function test_is_extra_available_reflects_manual_toggle(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class]);
        [$branch, $extra] = $this->makeBranchAndExtra();

        $this->assertTrue($this->service->isExtraAvailable($extra->id, $branch->id));

        $this->service->toggleExtra($extra->id, $branch->id, false, 'seasonal');
        $this->assertFalse($this->service->isExtraAvailable($extra->id, $branch->id));

        $this->service->toggleExtra($extra->id, $branch->id, true, null);
        // [TERRAIN-HEAL 2026-07-16 · MGMT-86-REACTIVATE] Réactivation flag-managed → row supprimé →
        // DISPONIBLE (règle V1 absent=dispo). Avant : restait indisponible (on_hand=0 fantôme) = bug borne.
        $this->assertTrue($this->service->isExtraAvailable($extra->id, $branch->id));
    }

    public function test_is_extra_available_returns_true_when_no_row(): void
    {
        [$branch, $extra] = $this->makeBranchAndExtra();
        $this->assertTrue($this->service->isExtraAvailable($extra->id, $branch->id));
    }

    public function test_get_unavailable_extra_ids_for_branch_returns_only_manually_flagged(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class]);
        [$branch, $extraA] = $this->makeBranchAndExtra();
        $extraB = ItemExtra::query()->create([
            'item_id' => $extraA->item_id,
            'name' => 'Olives',
            'price' => 1.00,
            'status' => Status::ACTIVE,
            'group_label' => 'sauces',
        ]);

        $this->service->toggleExtra($extraA->id, $branch->id, false, 'seasonal');

        $this->assertSame([$extraA->id], $this->service->getUnavailableExtraIdsForBranch($branch->id));
        $this->assertNotContains($extraB->id, $this->service->getUnavailableExtraIdsForBranch($branch->id));
    }

    public function test_cross_branch_isolation_for_extra_toggle(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class]);
        [$branchA, $extra] = $this->makeBranchAndExtra();
        $branchB = Branch::factory()->create();

        $this->service->toggleExtra($extra->id, $branchA->id, false, 'recipe_change');

        $this->assertFalse($this->service->isExtraAvailable($extra->id, $branchA->id));
        $this->assertTrue($this->service->isExtraAvailable($extra->id, $branchB->id));
        $this->assertSame([], $this->service->getUnavailableExtraIdsForBranch($branchB->id));
    }

    public function test_toggle_variation_unavailable_creates_stock_level_with_manual_reason(): void
    {
        Event::fake([ItemVariationAvailabilityChanged::class]);
        [$branch, $variation] = $this->makeBranchAndVariation();

        $this->service->toggleVariation($variation->id, $branch->id, false, 'quality_issue');

        $this->assertDatabaseHas('stock_levels', [
            'branch_id' => $branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $variation->id,
            'manual_unavailable_reason' => 'quality_issue',
        ]);
        $this->assertFalse($this->service->isVariationAvailable($variation->id, $branch->id));
    }

    public function test_get_unavailable_variation_ids_for_branch(): void
    {
        Event::fake([ItemVariationAvailabilityChanged::class]);
        [$branch, $variation] = $this->makeBranchAndVariation();

        $this->service->toggleVariation($variation->id, $branch->id, false, 'out_of_stock_manual');

        $this->assertSame([$variation->id], $this->service->getUnavailableVariationIdsForBranch($branch->id));
    }

    public function test_extra_event_dispatched_after_commit(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class]);
        [$branch, $extra] = $this->makeBranchAndExtra();

        $this->service->toggleExtra($extra->id, $branch->id, false, 'supplier_issue');

        Event::assertDispatched(
            ItemExtraAvailabilityChanged::class,
            fn (ItemExtraAvailabilityChanged $e): bool =>
                $e->extraId === (int) $extra->id
                && $e->branchId === (int) $branch->id
                && $e->isAvailable === false
                && $e->reason === 'supplier_issue'
        );
    }

    public function test_variation_event_dispatched_after_commit(): void
    {
        Event::fake([ItemVariationAvailabilityChanged::class]);
        [$branch, $variation] = $this->makeBranchAndVariation();

        $this->service->toggleVariation($variation->id, $branch->id, false, 'seasonal');

        Event::assertDispatched(
            ItemVariationAvailabilityChanged::class,
            fn (ItemVariationAvailabilityChanged $e): bool =>
                $e->variationId === (int) $variation->id
                && $e->branchId === (int) $branch->id
                && $e->isAvailable === false
                && $e->reason === 'seasonal'
        );
    }

    public function test_idempotent_re_toggle_same_state_does_not_dispatch_duplicate(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class]);
        [$branch, $extra] = $this->makeBranchAndExtra();

        $this->service->toggleExtra($extra->id, $branch->id, false, 'seasonal');
        $this->service->toggleExtra($extra->id, $branch->id, false, 'seasonal');

        Event::assertDispatchedTimes(ItemExtraAvailabilityChanged::class, 1);
    }

    public function test_branch_availability_snapshot_aggregates_items_extras_variations(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class, ItemVariationAvailabilityChanged::class]);
        [$branch, $extra] = $this->makeBranchAndExtra();
        $variation = $this->makeVariation();

        $this->service->toggleExtra($extra->id, $branch->id, false, 'seasonal');
        $this->service->toggleVariation($variation->id, $branch->id, false, 'recipe_change');

        $snapshot = $this->service->getBranchAvailabilitySnapshot($branch->id);

        $this->assertSame($branch->id, $snapshot['branch_id']);
        $this->assertSame([], $snapshot['items']);
        $this->assertCount(1, $snapshot['extras']);
        $this->assertSame($extra->id, $snapshot['extras'][0]['extra_id']);
        $this->assertSame('seasonal', $snapshot['extras'][0]['reason']);
        $this->assertCount(1, $snapshot['variations']);
        $this->assertSame($variation->id, $snapshot['variations'][0]['variation_id']);
        $this->assertSame('recipe_change', $snapshot['variations'][0]['reason']);
    }

    /**
     * @return array{0: Branch, 1: ItemExtra}
     */
    private function makeBranchAndExtra(): array
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar',
            'price' => 0.80,
            'status' => Status::ACTIVE,
            'group_label' => 'sauces',
            'is_available' => true,
        ]);

        return [$branch, $extra];
    }

    /**
     * @return array{0: Branch, 1: ItemVariation}
     */
    private function makeBranchAndVariation(): array
    {
        $branch = Branch::factory()->create();
        $variation = $this->makeVariation();

        return [$branch, $variation];
    }

    private function makeVariation(): ItemVariation
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create([
            'is_available' => true,
        ]);

        return ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Grand format',
            'price' => 2.00,
            'status' => Status::ACTIVE,
        ]);
    }
}
