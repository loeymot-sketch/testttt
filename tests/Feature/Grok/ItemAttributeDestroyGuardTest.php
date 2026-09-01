<?php

namespace Tests\Feature\Grok;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gestes commerçant : supprimer un attribut (Viande, Sauce, Taille).
 * Avant : la corbeille acceptait même si des variantes ou le composeur
 * s'en servaient encore — le wizard tacos se retrouvait sans viandes.
 */
class ItemAttributeDestroyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_cannot_delete_attribute_still_used_by_a_variation(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);

        $attribute = ItemAttribute::factory()->create(['name' => 'Viande', 'status' => Status::ACTIVE]);
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Poulet',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);

        $response = $this->deleteJson('/api/admin/setting/item-attribute/'.$attribute->id);

        $response->assertStatus(422);
        $this->assertNotNull(ItemAttribute::query()->find($attribute->id));
        $this->assertNotNull(ItemVariation::query()->where('item_attribute_id', $attribute->id)->first());
    }

    public function test_cannot_delete_attribute_still_bound_to_composer_step(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);

        $attribute = ItemAttribute::factory()->create(['name' => 'Sauce', 'status' => Status::ACTIVE]);
        $profile = ItemWizardProfile::factory()->create();
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'source_type' => 'item_attribute',
            'source_ref' => mb_strtolower($attribute->name),
            'source_item_attribute_id' => $attribute->id,
            'step_key' => 'sauce',
            'label' => 'Sauce',
        ]);

        $response = $this->deleteJson('/api/admin/setting/item-attribute/'.$attribute->id);

        $response->assertStatus(422);
        $this->assertNotNull(ItemAttribute::query()->find($attribute->id));
    }

    public function test_unused_attribute_can_be_deleted(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);

        $attribute = ItemAttribute::factory()->create(['name' => 'Couleur vitrine', 'status' => Status::ACTIVE]);

        $response = $this->deleteJson('/api/admin/setting/item-attribute/'.$attribute->id);

        $response->assertStatus(202);
        $this->assertNull(ItemAttribute::query()->find($attribute->id));
    }

    private function admin(): \App\Models\User
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        return $admin;
    }
}
