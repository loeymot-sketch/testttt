<?php

namespace Tests\Feature\Grok;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mensonges d'écran confirmés par cartographie + adversaire.
 * Chaque cas frappe la route HTTP réelle.
 */
class MerchantScreenLieFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_category_show_returns_kiosk_menu_flags_so_save_cannot_wipe_them(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $category = ItemCategory::factory()->create([
            'name' => 'Tacos',
            'status' => Status::ACTIVE,
            'default_menu_kiosk' => true,
            'sauce_included_menu' => true,
        ]);

        $response = $this->getJson('/api/admin/setting/item-category/show/'.$category->id);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('data.default_menu_kiosk'));
        $this->assertTrue((bool) $response->json('data.sauce_included_menu'));
    }

    public function test_variation_group_by_attribute_keeps_visible_on(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande', 'status' => Status::ACTIVE]);
        ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Poulet',
            'price' => 0,
            'status' => Status::ACTIVE,
            'visible_on' => ['pos'],
        ]);

        $response = $this->getJson('/api/admin/item/variation/group-by-attribute/'.$item->id);

        $response->assertOk();
        $children = $response->json('data.0.children') ?? $response->json('data.0.item_variations');
        $this->assertIsArray($children);
        $this->assertSame(['pos'], $children[0]['visible_on'] ?? $children[0]['visibleOn'] ?? null);
    }

    public function test_cannot_delete_attribute_referenced_by_mixed_case_wizard_source_ref(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande 3', 'status' => Status::ACTIVE]);
        $profile = ItemWizardProfile::factory()->create();
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'source_type' => 'item_attribute',
            'source_ref' => 'Viande 3',
            'source_item_attribute_id' => null,
            'step_key' => 'viande_3',
            'label' => 'Viande 3',
        ]);

        $response = $this->deleteJson('/api/admin/setting/item-attribute/'.$attribute->id);

        $response->assertStatus(422);
        $this->assertNotNull(ItemAttribute::query()->find($attribute->id));
    }

    public function test_http_cannot_delete_or_rename_pos_operator(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $role = Role::query()->where('name', 'POS Operator')->where('guard_name', 'sanctum')->first();
        $this->assertNotNull($role);

        $this->deleteJson('/api/admin/setting/role/'.$role->id)->assertStatus(422);
        $this->assertNotNull(Role::query()->find($role->id));

        $this->putJson('/api/admin/setting/role/'.$role->id, ['name' => 'Ancien caissier'])
            ->assertStatus(422);
        $this->assertSame('POS Operator', $role->fresh()->name);
    }

    public function test_composer_publish_always_saves_draft_first(): void
    {
        $src = file_get_contents(
            base_path('resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue')
        );
        $this->assertSame(
            1,
            preg_match('/async publish\(\) \{([\s\S]*?)\},\s*async unpublish/', $src, $m)
        );
        $this->assertStringContainsString('await this.saveDraft();', $m[1]);
        $this->assertStringContainsString('conflictDetected', $m[1]);
    }

    private function admin(): \App\Models\User
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        return $admin;
    }
}
