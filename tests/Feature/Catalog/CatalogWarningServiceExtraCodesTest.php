<?php

namespace Tests\Feature\Catalog;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Services\Catalog\CatalogWarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sentinel — CV1-LIFECYCLE-UX-001 task 1.5.
 *
 * CatalogWarningService extra codes: missing_photo, branch_availability_unset, high_daily_consumed.
 *
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md §1.5
 */
class CatalogWarningServiceExtraCodesTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CatalogWarningService
    {
        return new CatalogWarningService();
    }

    private static function tinyPngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
    }

    private function makeCatalogCleanItem(): Item
    {
        return Item::factory()->create([
            'channels'  => ['pos'],
            'item_type' => 1,
        ]);
    }

    public function test_item_without_photo_emits_missing_photo_warning(): void
    {
        $item = $this->makeCatalogCleanItem();

        $warnings = $this->service()->forItem($item, null);
        $codes = array_column($warnings, 'code');

        $this->assertContains(CatalogWarningService::CODE_MISSING_PHOTO, $codes);
        $entry = collect($warnings)->firstWhere('code', CatalogWarningService::CODE_MISSING_PHOTO);
        $this->assertSame(CatalogWarningService::SEVERITY_INFO, $entry['severity']);
        $this->assertSame([], $entry['context']);
    }

    public function test_item_with_photo_does_not_emit_missing_photo_warning(): void
    {
        $item = $this->makeCatalogCleanItem();
        $item->addMediaFromString(self::tinyPngBytes())
            ->usingFileName('sentinel.png')
            ->toMediaCollection('item');

        $warnings = $this->service()->forItem($item->fresh(), null);
        $codes = array_column($warnings, 'code');

        $this->assertNotContains(CatalogWarningService::CODE_MISSING_PHOTO, $codes);
    }

    public function test_branch_availability_unset_emits_warning_when_no_row_exists(): void
    {
        $item = $this->makeCatalogCleanItem();
        $branch = Branch::factory()->create();

        $warnings = $this->service()->forItem($item, (int) $branch->id);
        $codes = array_column($warnings, 'code');

        $this->assertContains(CatalogWarningService::CODE_BRANCH_AVAILABILITY_UNSET, $codes);
        $entry = collect($warnings)->firstWhere('code', CatalogWarningService::CODE_BRANCH_AVAILABILITY_UNSET);
        $this->assertSame(CatalogWarningService::SEVERITY_INFO, $entry['severity']);
        $this->assertSame(['branch_id' => (int) $branch->id], $entry['context']);
    }

    public function test_high_daily_consumed_warning_emits_when_ratio_above_85_percent(): void
    {
        $item = $this->makeCatalogCleanItem();
        $branch = Branch::factory()->create();

        ItemBranchAvailability::query()->create([
            'item_id'             => $item->id,
            'branch_id'           => $branch->id,
            'is_available'        => true,
            'max_daily_qty'       => 10,
            'daily_consumed_qty'  => 9,
            'daily_reset_at'      => now()->toDateString(),
        ]);

        $warnings = $this->service()->forItem($item->fresh(), (int) $branch->id);
        $codes = array_column($warnings, 'code');

        $this->assertContains(CatalogWarningService::CODE_HIGH_DAILY_CONSUMED, $codes);
        $entry = collect($warnings)->firstWhere('code', CatalogWarningService::CODE_HIGH_DAILY_CONSUMED);
        $this->assertSame(CatalogWarningService::SEVERITY_WARNING, $entry['severity']);
        $this->assertSame([
            'consumed' => 9,
            'max'      => 10,
            'ratio'    => 0.9,
        ], $entry['context']);
    }

    public function test_high_daily_consumed_does_not_emit_when_ratio_below_85_percent(): void
    {
        $item = $this->makeCatalogCleanItem();
        $branch = Branch::factory()->create();

        ItemBranchAvailability::query()->create([
            'item_id'             => $item->id,
            'branch_id'           => $branch->id,
            'is_available'        => true,
            'max_daily_qty'       => 10,
            'daily_consumed_qty'  => 5,
            'daily_reset_at'      => now()->toDateString(),
        ]);

        $warnings = $this->service()->forItem($item->fresh(), (int) $branch->id);
        $codes = array_column($warnings, 'code');

        $this->assertNotContains(CatalogWarningService::CODE_HIGH_DAILY_CONSUMED, $codes);
    }

    /**
     * [GOAL_WIZARD_DYNAMIC W7] A complex item with NO own profile still emits
     * the missing-composer blocker — UNLESS it inherits a published category
     * wizard. This pair guards the false-blocker fix.
     */
    private function makeComplexItem(ItemCategory $category): Item
    {
        $item = Item::factory()->create([
            'channels'         => ['pos'],
            'item_type'        => 1,
            'item_category_id' => $category->id,
        ]);
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande', 'status' => Status::ACTIVE]);
        ItemVariation::query()->create([
            'item_id'           => $item->id,
            'item_attribute_id' => $attribute->id,
            'name'              => 'Poulet',
            'price'             => 0,
            'status'            => Status::ACTIVE,
            'visible_on'        => ['pos', 'kiosk'],
        ]);

        return $item->fresh();
    }

    public function test_complex_item_without_any_wizard_still_emits_missing_blocker(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = $this->makeComplexItem($category);

        $warnings = $this->service()->forItem($item, null);
        $codes = array_column($warnings, 'code');

        $this->assertContains(CatalogWarningService::CODE_COMPOSER_MISSING_FOR_COMPLEX, $codes);
    }

    public function test_complex_item_inheriting_published_category_wizard_does_not_emit_missing_blocker(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = $this->makeComplexItem($category);

        // Published CATEGORY wizard (item_id = null) covering the item's category.
        ItemWizardProfile::query()->create([
            'item_id'          => null,
            'item_category_id' => $category->id,
            'template'         => 'custom',
            'version'          => 1,
            'is_published'     => true,
            'published_at'     => now(),
            'branch_id_scope'  => null,
        ]);

        $warnings = $this->service()->forItem($item, null);
        $codes = array_column($warnings, 'code');

        $this->assertNotContains(CatalogWarningService::CODE_COMPOSER_MISSING_FOR_COMPLEX, $codes);
    }
}
