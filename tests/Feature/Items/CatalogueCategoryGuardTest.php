<?php

namespace Tests\Feature\Items;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Models\Tax;
use App\Services\ItemCategoryService;
use App\Services\Menu\MenuProjectionService;
use Database\Factories\BranchFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Central catalogue defects — revenue-protection guards.
 *
 * [CAT-DEL-01 FIX] Deleting a populated category silently removed ALL its
 * active items from the sellable kiosk/POS/web menu projection (revenue loss).
 * The destroy() path now REJECTS with a 409 when the category still has active
 * items; the items therefore remain in the MenuProjection (still sellable).
 *
 * [CAT-VAR-02 FIX] ItemService::update could orphan existing variations when
 * the incoming payload was empty / contained only new rows, because the
 * diff-delete was guarded behind `if ($variationIdsArray)`. The keep-set diff
 * now always runs so clearing variations actually deletes the orphans.
 */
class CatalogueCategoryGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;
    private Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seedItemPermissions();

        $branch = BranchFactory::new()->create(['status' => Status::ACTIVE]);
        $this->branchId = (int) $branch->id;
        $this->tax = Tax::factory()->create(['status' => Status::ACTIVE]);
    }

    private function seedItemPermissions(): void
    {
        foreach (['items', 'items_create', 'items_edit', 'items_delete', 'items_show'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }

    private function actingAsAdmin(): void
    {
        $admin = UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items', 'items_create', 'items_edit', 'items_delete', 'items_show']);
        Sanctum::actingAs($admin, ['*']);
    }

    private function apiKey(): string
    {
        return env('MIX_API_KEY', 'test-api-key');
    }

    // ---------------------------------------------------------------------
    // CAT-DEL-01 — populated category delete must NOT orphan sellable items
    // ---------------------------------------------------------------------

    /** [CAT-DEL-01 FIX] */
    public function test_destroy_populated_category_throws_409_and_keeps_items_sellable(): void
    {
        $category = ItemCategory::factory()->create([
            'name' => 'Tacos',
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);

        $itemA = $this->makeItem('Tacos M', $category);
        $itemB = $this->makeItem('Tacos L', $category);

        $service = app(ItemCategoryService::class);

        // Pre-fix: destroy() silently soft-deleted the category, dropping both
        // items from every surface projection. Post-fix: it must reject (409).
        $threw = false;
        try {
            $service->destroy($category->fresh());
        } catch (\Throwable $e) {
            $threw = true;
            $this->assertSame(409, (int) $e->getCode(), 'Guard must reject with HTTP 409.');
            $this->assertStringContainsString('2', $e->getMessage(), 'Message must report the active item count.');
            $this->assertStringContainsString('actif', $e->getMessage(), 'Message must be FR (mentions "actif").');
        }
        $this->assertTrue($threw, 'destroy() of a populated category must throw, not silently succeed.');

        // Real consequence #1: the category row still exists (not soft-deleted).
        $this->assertNull($category->fresh()->deleted_at, 'Category must remain after a blocked delete.');

        // Real consequence #2 (the actual revenue oracle): the items still
        // appear in the sellable kiosk + POS menu projection.
        $projection = app(MenuProjectionService::class);
        $kioskItemIds = $this->flattenItems($projection->forChannel('kiosk', $this->branchId));
        $posItemIds = $this->flattenItems($projection->forChannel('pos', $this->branchId));

        $this->assertContains((int) $itemA->id, $kioskItemIds, 'Item A must stay sellable on kiosk.');
        $this->assertContains((int) $itemB->id, $kioskItemIds, 'Item B must stay sellable on kiosk.');
        $this->assertContains((int) $itemA->id, $posItemIds, 'Item A must stay sellable on POS.');
        $this->assertContains((int) $itemB->id, $posItemIds, 'Item B must stay sellable on POS.');
    }

    /** [CAT-DEL-01 FIX] An empty category (no active items) deletes normally. */
    public function test_destroy_empty_category_still_succeeds(): void
    {
        $category = ItemCategory::factory()->create([
            'name' => 'Empty Cat',
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);

        app(ItemCategoryService::class)->destroy($category->fresh());

        $this->assertSoftDeleted('item_categories', ['id' => $category->id]);
    }

    /** [CAT-DEL-01 FIX] A category whose only items are inactive deletes normally. */
    public function test_destroy_category_with_only_inactive_items_succeeds(): void
    {
        $category = ItemCategory::factory()->create([
            'name' => 'Inactive Only',
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);

        // INACTIVE item — items() relation is ACTIVE-filtered, so this is not
        // counted as a sellable item blocking the delete.
        Item::factory()->create([
            'name' => 'Retired Item',
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'status' => Status::INACTIVE,
            'is_available' => true,
        ]);

        app(ItemCategoryService::class)->destroy($category->fresh());

        $this->assertSoftDeleted('item_categories', ['id' => $category->id]);
    }

    // ---------------------------------------------------------------------
    // CAT-VAR-02 — variation diff must delete orphans even when payload empties
    // (driven through the real PUT /api/admin/item/{item} HTTP path)
    // ---------------------------------------------------------------------

    /** [CAT-VAR-02 FIX] Clearing all variations actually deletes the old rows. */
    public function test_update_with_empty_variations_payload_deletes_orphans(): void
    {
        $this->actingAsAdmin();
        [$item, $varA, $varB] = $this->makeItemWithTwoVariations('Menu Box');

        $response = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->postJson("/api/admin/item/{$item->id}", $this->itemUpdatePayload($item, [
                'variations' => json_encode([]),
            ]));

        $response->assertStatus(200);

        // Real consequence: both old variations are gone, none left dangling.
        $this->assertSoftDeleted('item_variations', ['id' => $varA->id]);
        $this->assertSoftDeleted('item_variations', ['id' => $varB->id]);
        $this->assertSame(0, $item->fresh()->variations()->count(), 'No orphan variations may remain.');
    }

    /** [CAT-VAR-02 FIX] A payload of only-new rows deletes the old ones. */
    public function test_update_with_only_new_variations_deletes_old_and_creates_new(): void
    {
        $this->actingAsAdmin();
        [$item, $varA, $varB] = $this->makeItemWithTwoVariations('Menu Box 2');

        $response = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->postJson("/api/admin/item/{$item->id}", $this->itemUpdatePayload($item, [
                'variations' => json_encode([
                    ['name' => 'Brand New', 'price' => 7.50, 'item_attribute_id' => $this->variationAttributeId],
                ]),
            ]));

        $response->assertStatus(200);

        $this->assertSoftDeleted('item_variations', ['id' => $varA->id]);
        $this->assertSoftDeleted('item_variations', ['id' => $varB->id]);

        $remaining = $item->fresh()->variations;
        $this->assertCount(1, $remaining, 'Only the one new variation must remain.');
        $this->assertSame('Brand New', $remaining->first()->name);
    }

    /** [CAT-VAR-02 FIX] A normal mixed update keeps exactly the right set. */
    public function test_update_with_mixed_variations_keeps_correct_set(): void
    {
        $this->actingAsAdmin();
        [$item, $varA, $varB] = $this->makeItemWithTwoVariations('Menu Box 3');

        // Keep varA (with id, repriced), drop varB (absent), add one new row.
        $response = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->postJson("/api/admin/item/{$item->id}", $this->itemUpdatePayload($item, [
                'variations' => json_encode([
                    ['id' => $varA->id, 'name' => 'Kept A', 'price' => 9.99],
                    ['name' => 'Fresh C', 'price' => 4.00, 'item_attribute_id' => $this->variationAttributeId],
                ]),
            ]));

        $response->assertStatus(200);

        $this->assertSoftDeleted('item_variations', ['id' => $varB->id]);

        $remaining = $item->fresh()->variations->keyBy('name');
        $this->assertCount(2, $remaining, 'Exactly the kept + new variation remain.');
        $this->assertTrue($remaining->has('Kept A'));
        $this->assertTrue($remaining->has('Fresh C'));
        $this->assertSame('9.99', (string) round((float) $remaining->get('Kept A')->price, 2));
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    private function makeItem(string $name, ItemCategory $category): Item
    {
        return Item::factory()->create([
            'name' => $name,
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'status' => Status::ACTIVE,
            'channels' => null,
            'is_available' => true,
        ]);
    }

    private ?int $variationAttributeId = null;

    /** @return array{0: Item, 1: ItemVariation, 2: ItemVariation} */
    private function makeItemWithTwoVariations(string $name): array
    {
        $category = ItemCategory::factory()->create([
            'name' => $name . ' Cat',
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);
        $item = Item::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'item_category_id' => $category->id,
            'item_type' => 1,
            'price' => 10.00,
            'is_featured' => 1,
            'status' => Status::ACTIVE,
            'order' => 1,
            'tax_id' => $this->tax->id,
        ]);

        $attribute = \App\Models\ItemAttribute::factory()->create(['status' => Status::ACTIVE]);
        $this->variationAttributeId = (int) $attribute->id;

        $varA = ItemVariation::create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Variation A',
            'price' => 5.00,
            'status' => Status::ACTIVE,
        ]);
        $varB = ItemVariation::create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Variation B',
            'price' => 6.00,
            'status' => Status::ACTIVE,
        ]);

        return [$item->fresh(), $varA, $varB];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemUpdatePayload(Item $item, array $overrides = []): array
    {
        return array_merge([
            'name' => $item->name,
            'item_category_id' => $item->item_category_id,
            'item_type' => $item->item_type,
            'price' => $item->price,
            'is_featured' => $item->is_featured,
            'status' => $item->status,
            'order' => $item->order,
        ], $overrides);
    }

    /** @return array<int,int> item ids visible in the projection */
    private function flattenItems(array $projection): array
    {
        return collect($projection['categories'] ?? [])
            ->flatMap(static fn (array $category) => $category['items'] ?? [])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
