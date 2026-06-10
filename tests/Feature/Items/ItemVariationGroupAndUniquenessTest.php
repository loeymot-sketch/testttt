<?php

namespace Tests\Feature\Items;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID CC-01 + RED-01 (P1, ultra-audit 2026-06-10) | @plan GOAL_ULTRA_AUDIT_SYSTEMES LOT C
 *
 * CC-01 — GET /admin/item/variation/group-by-attribute/{item} returned 500 for
 * EVERY item with variations: ItemVariationService::listGroupByAttribute built
 * partial stdClass children (no visible_on/description/image_path/thumb/is_new)
 * while ItemVariationResource reads $this->visible_on without null-coalescing.
 * The ResourceCollection serializes LAZILY (after the controller return), so
 * the controller's catch(\Throwable) was mechanically bypassed.
 *
 * RED-01 — ItemVariationRequest scoped name uniqueness to item_id ONLY, so two
 * legitimate same-named options under different attributes ("Poulet mariné" in
 * Viande 1 + Viande 2) made BOTH non-editable: any update (price-only included)
 * hit 422 because the twin row matched.
 */
class ItemVariationGroupAndUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Item $item;
    private ItemAttribute $attr1;
    private ItemAttribute $attr2;
    private ItemVariation $twinA;
    private ItemVariation $twinB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Branch::factory()->create();
        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');

        $this->item  = Item::factory()->create();
        $this->attr1 = ItemAttribute::factory()->create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
        $this->attr2 = ItemAttribute::factory()->create(['name' => 'Viande 2', 'status' => Status::ACTIVE]);

        $this->twinA = ItemVariation::create([
            'item_id'           => $this->item->id,
            'item_attribute_id' => $this->attr1->id,
            'name'              => 'Poulet mariné',
            'price'             => 0,
            'status'            => Status::ACTIVE,
        ]);
        $this->twinB = ItemVariation::create([
            'item_id'           => $this->item->id,
            'item_attribute_id' => $this->attr2->id,
            'name'              => 'Poulet mariné',
            'price'             => 0,
            'status'            => Status::ACTIVE,
        ]);
    }

    public function test_group_by_attribute_returns_200_with_full_child_shape(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/item/variation/group-by-attribute/' . $this->item->id);

        $response->assertSuccessful();

        $groups = $response->json('data');
        $this->assertCount(2, $groups, 'One group per attribute expected.');

        $child = $groups[0]['children'][0] ?? null;
        $this->assertNotNull($child);
        // The 500 came from missing serialization fields on partial stdClass.
        $this->assertArrayHasKey('visible_on', $child);
        $this->assertArrayHasKey('thumb', $child);
        $this->assertArrayHasKey('is_new', $child);
    }

    public function test_price_only_update_on_twin_named_variation_passes(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/admin/item/variation/' . $this->item->id . '/' . $this->twinA->id, [
                'name'              => 'Poulet mariné',
                'item_attribute_id' => $this->attr1->id,
                'price'             => 1.50,
                'status'            => Status::ACTIVE,
            ]);

        $response->assertSuccessful();
        $this->assertSame(
            1.5,
            (float) ItemVariation::findOrFail($this->twinA->id)->price,
            'Twin-named variation must stay editable (price-only change).'
        );
    }

    public function test_duplicate_name_within_same_attribute_still_rejected(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/item/variation/' . $this->item->id, [
                'name'              => 'Poulet mariné',
                'item_attribute_id' => $this->attr1->id,
                'price'             => 2.00,
                'status'            => Status::ACTIVE,
            ]);

        $response->assertStatus(422);
    }
}
