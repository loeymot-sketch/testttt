<?php

namespace Tests\Feature\Catalog;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sentinel — CV1-LIFECYCLE-UX-001 task 1.7 (admin item duplication).
 *
 * Plan: plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md §1.7
 */
class ItemDuplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_duplicate_creates_independent_copy_with_suffix(): void
    {
        $this->actingItemsCreateAdmin();
        $original = $this->item(['name' => 'Tacos M', 'price' => 12.34]);

        $response = $this->postJson("/api/admin/item/{$original->id}/duplicate");

        $response->assertOk();
        $copy = Item::query()->findOrFail($response->json('data.id'));

        $this->assertNotSame($original->id, $copy->id);
        $this->assertSame('Tacos M (copie)', $copy->name);
        $this->assertEquals($original->price, $copy->price);
    }

    public function test_duplicate_copies_variations_extras_and_addons(): void
    {
        $this->actingItemsCreateAdmin();
        $original = $this->item();
        $attribute = ItemAttribute::factory()->create();
        $addonItem = $this->item(['name' => 'Boisson']);

        $variations = collect(range(1, 3))->map(fn (int $index) => ItemVariation::query()->create([
            'item_id' => $original->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Variation ' . $index,
            'price' => $index,
            'status' => Status::ACTIVE,
        ]));
        $extras = collect(range(1, 2))->map(fn (int $index) => ItemExtra::query()->create([
            'item_id' => $original->id,
            'name' => 'Extra ' . $index,
            'price' => $index,
            'status' => Status::ACTIVE,
        ]));
        $addon = ItemAddon::query()->create([
            'item_id' => $original->id,
            'addon_item_id' => $addonItem->id,
            'addon_item_variation' => ['size' => '33cl'],
            'role' => 'drink',
        ]);

        $response = $this->postJson("/api/admin/item/{$original->id}/duplicate");

        $response->assertOk();
        $copyId = (int) $response->json('data.id');
        $copyVariationIds = ItemVariation::query()->where('item_id', $copyId)->pluck('id')->all();
        $copyExtraIds = ItemExtra::query()->where('item_id', $copyId)->pluck('id')->all();
        $copyAddonIds = ItemAddon::query()->where('item_id', $copyId)->pluck('id')->all();

        $this->assertCount(3, $copyVariationIds);
        $this->assertCount(2, $copyExtraIds);
        $this->assertCount(1, $copyAddonIds);
        $this->assertEmpty(array_intersect($variations->pluck('id')->all(), $copyVariationIds));
        $this->assertEmpty(array_intersect($extras->pluck('id')->all(), $copyExtraIds));
        $this->assertNotContains($addon->id, $copyAddonIds);
    }

    public function test_duplicate_copies_composer_profile_as_draft(): void
    {
        $this->actingItemsCreateAdmin();
        $original = $this->item();
        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $original->id,
            'template' => 'tacos',
            'version' => 3,
            'is_published' => true,
            'published_at' => now(),
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'viande',
            'label' => 'Viande',
            'position' => 1,
        ]);

        $response = $this->postJson("/api/admin/item/{$original->id}/duplicate");

        $response->assertOk();
        $copyId = (int) $response->json('data.id');
        $draft = ItemWizardProfile::query()->where('item_id', $copyId)->firstOrFail();

        $this->assertFalse((bool) $draft->is_published);
        $this->assertNull($draft->published_at);
        $this->assertSame(4, (int) $draft->version);
        $this->assertDatabaseHas('item_wizard_steps', [
            'profile_id' => $draft->id,
            'step_key' => 'viande',
        ]);
    }

    public function test_duplicate_does_not_touch_order_history(): void
    {
        $this->actingItemsCreateAdmin();
        $original = $this->item();
        $branch = Branch::factory()->create();
        $order = Order::factory()->create(['branch_id' => $branch->id]);
        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $original->id,
            'quantity' => 1,
            'discount' => 0,
            'price' => 10,
            'total_price' => 10,
        ]);

        $response = $this->postJson("/api/admin/item/{$original->id}/duplicate");

        $response->assertOk();
        $this->assertSame(1, OrderItem::query()->count());
        $this->assertSame($original->id, $orderItem->fresh()->item_id);
    }

    public function test_duplicate_starts_as_inactive_status(): void
    {
        $this->actingItemsCreateAdmin();
        $original = $this->item(['status' => Status::ACTIVE]);

        $response = $this->postJson("/api/admin/item/{$original->id}/duplicate");

        $response->assertOk();
        $copy = Item::query()->findOrFail($response->json('data.id'));

        $this->assertSame(Status::INACTIVE, (int) $copy->status);
    }

    private function actingItemsCreateAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'items_create', 'guard_name' => 'sanctum']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_create');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    private function item(array $attributes = []): Item
    {
        return Item::factory()->create($attributes);
    }
}
