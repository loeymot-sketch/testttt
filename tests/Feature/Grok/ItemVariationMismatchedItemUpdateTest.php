<?php

namespace Tests\Feature\Grok;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Gestes commerçant : modifier / supprimer une variante (taille, sauce, viande)
 * depuis l'admin. L'identifiant produit dans l'URL doit correspondre à la
 * variante, sinon l'écran mentait (2xx sans rien changer en base).
 */
class ItemVariationMismatchedItemUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        foreach (['items', 'items_create', 'items_edit', 'items_show'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }

    public function test_put_variation_with_wrong_item_id_does_not_change_price(): void
    {
        [$admin, $owner, $other, $variation, $attribute] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->putJson(
            '/api/admin/item/variation/'.$other->id.'/'.$variation->id,
            [
                'name' => $variation->name,
                'item_attribute_id' => $attribute->id,
                'price' => 9.99,
                'status' => Status::ACTIVE,
            ]
        );

        $response->assertStatus(422);
        $this->assertSame(
            '0.500000',
            $variation->fresh()->price,
            'le prix de la variante du vrai produit ne doit pas bouger'
        );
    }

    public function test_put_variation_on_owning_item_updates_price(): void
    {
        [$admin, $owner, $other, $variation, $attribute] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->putJson(
            '/api/admin/item/variation/'.$owner->id.'/'.$variation->id,
            [
                'name' => $variation->name,
                'item_attribute_id' => $attribute->id,
                'price' => 1.50,
                'status' => Status::ACTIVE,
            ]
        );

        $response->assertOk();
        $this->assertSame('1.500000', $variation->fresh()->price);
    }

    public function test_delete_variation_with_wrong_item_id_keeps_the_row(): void
    {
        [$admin, $owner, $other, $variation] = $this->fixture();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->deleteJson(
            '/api/admin/item/variation/'.$other->id.'/'.$variation->id
        );

        $response->assertStatus(422);
        $this->assertNotNull(ItemVariation::query()->find($variation->id));
    }

    /**
     * @return array{0: \App\Models\User, 1: Item, 2: Item, 3: ItemVariation, 4: ItemAttribute}
     */
    private function fixture(): array
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items', 'items_create', 'items_edit', 'items_show']);

        $owner = Item::factory()->create(['name' => 'Tacos M', 'status' => Status::ACTIVE]);
        $other = Item::factory()->create(['name' => 'Tacos L', 'status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande', 'status' => Status::ACTIVE]);

        $variation = ItemVariation::query()->create([
            'item_id' => $owner->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Poulet',
            'price' => 0.50,
            'status' => Status::ACTIVE,
        ]);

        return [$admin, $owner, $other, $variation, $attribute];
    }
}
