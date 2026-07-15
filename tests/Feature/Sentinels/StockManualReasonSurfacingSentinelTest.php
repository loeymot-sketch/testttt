<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use App\Services\Stock\ChoiceAvailabilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [F-016a-BIS] Sentinel — manual rupture reason surfaces in resolver output.
 *
 * Locks the priority contract added to ChoiceAvailabilityResolver::availabilityFromLevel:
 *  - manual_unavailable_reason IS NOT NULL  =>  unavailable + that reason
 *    (overrides on_hand even when on_hand > 0)
 *  - manual_unavailable_reason IS NULL      =>  fall back to on_hand check
 *    (auto stock_rupture reason when on_hand <= 0)
 *
 * Without this contract, manager-flagged extras/variations would silently appear
 * available again the moment a delivery refreshes on_hand > 0, breaking the
 * "rupture livreur" / "saisonnier" UX promise.
 */
class StockManualReasonSurfacingSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_extra_rupture_overrides_positive_on_hand(): void
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
        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'on_hand' => 100, // plenty in stock
            'reserved' => 0,
            'manual_unavailable_reason' => 'seasonal',
            'manual_unavailable_since' => now(),
        ]);

        $snapshot = app(ChoiceAvailabilityResolver::class)->snapshotForItem(
            $item->fresh(['extras']),
            (int) $branch->id
        );

        $this->assertFalse($snapshot['extras'][$extra->id]['is_available']);
        $this->assertSame('seasonal', $snapshot['extras'][$extra->id]['unavailable_reason']);
    }

    public function test_manual_variation_rupture_overrides_positive_on_hand(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create(['is_available' => true]);
        $variation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Maxi',
            'price' => 2.00,
            'status' => Status::ACTIVE,
        ]);
        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $variation->id,
            'on_hand' => 100,
            'reserved' => 0,
            'manual_unavailable_reason' => 'recipe_change',
            'manual_unavailable_since' => now(),
        ]);

        $snapshot = app(ChoiceAvailabilityResolver::class)->snapshotForItem(
            $item->fresh(['variations']),
            (int) $branch->id
        );

        $this->assertFalse($snapshot['variations'][$variation->id]['is_available']);
        $this->assertSame('recipe_change', $snapshot['variations'][$variation->id]['unavailable_reason']);
    }

    public function test_auto_stock_rupture_reason_still_applies_when_no_manual_flag(): void
    {
        // Without manual override, on_hand=0 must continue to surface as
        // 'stock_rupture' (not null), so frontend handlers can distinguish
        // automatic-from-decrement vs manager-flagged outages.
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Olives',
            'price' => 0.50,
            'status' => Status::ACTIVE,
            'group_label' => 'sauces',
            'is_available' => true,
        ]);
        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'on_hand' => 0,
            'reserved' => 0,
            'manual_unavailable_reason' => null,
        ]);

        $snapshot = app(ChoiceAvailabilityResolver::class)->snapshotForItem(
            $item->fresh(['extras']),
            (int) $branch->id
        );

        $this->assertFalse($snapshot['extras'][$extra->id]['is_available']);
        $this->assertSame('stock_rupture', $snapshot['extras'][$extra->id]['unavailable_reason']);
    }

    public function test_assert_selections_orderable_blocks_manually_flagged_extra(): void
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
        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'on_hand' => 50,
            'reserved' => 0,
            'manual_unavailable_reason' => 'supplier_issue',
            'manual_unavailable_since' => now(),
        ]);

        // [UX-86 2026-07-15 commit 82be92370] Le message client ne FUITE PLUS le code raison brut
        // (supplier_issue) — il est nommé/friendly. La raison brute reste surfacée dans le
        // composition_snapshot (cf. tests _overrides_positive_on_hand). On vérifie ici le BLOCAGE
        // effectif de l'extra manuellement rupturé, via le message nommé + le nom de l'article.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cheddar');

        app(ChoiceAvailabilityResolver::class)->assertSelectionsOrderable(
            (int) $branch->id,
            collect(),
            collect([$extra]),
            collect(),
            'kiosk',
            false
        );
    }
}
