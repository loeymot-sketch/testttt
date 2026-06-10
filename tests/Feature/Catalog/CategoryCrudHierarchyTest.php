<?php

namespace Tests\Feature\Catalog;

use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [GOAL CMS GESTION 2026-06-10 — Wave C1, T-C1.1/T-C1.4]
 *
 * Sous-catégories : le schéma existe (item_categories.parent_id, garde 2
 * niveaux via ItemCategoryHierarchyService) mais la surface API doit :
 *  - accepter parent_id au store/update (déjà validé par ItemCategoryRequest)
 *  - REFUSER la profondeur 3 (sous-sous-catégorie)
 *  - ÉMETTRE parent_id dans ItemCategoryResource (sinon aucune UI ne peut
 *    construire l'arbre — gap RED P1-5 de l'audit plan 2026-06-10)
 */
class CategoryCrudHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        Permission::firstOrCreate(['name' => 'settings', 'guard_name' => 'sanctum']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['settings']);

        return $user;
    }

    private function withApiKey(): self
    {
        return $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')]);
    }

    public function test_store_with_parent_creates_subcategory(): void
    {
        $parent = ItemCategory::factory()->create();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->withApiKey()
            ->postJson('/api/admin/setting/item-category', [
                'name'      => 'Sous-catégorie Signature',
                'status'    => 5,
                'parent_id' => $parent->id,
            ]);

        $response->assertSuccessful();

        $child = ItemCategory::query()->where('name', 'Sous-catégorie Signature')->first();
        $this->assertNotNull($child);
        $this->assertSame((int) $parent->id, (int) $child->parent_id);
    }

    public function test_store_under_a_subcategory_is_rejected_depth_two_max(): void
    {
        $parent = ItemCategory::factory()->create();
        $child = ItemCategory::factory()->create(['parent_id' => $parent->id]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->withApiKey()
            ->postJson('/api/admin/setting/item-category', [
                'name'      => 'Niveau trois interdit',
                'status'    => 5,
                'parent_id' => $child->id,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('item_categories', ['name' => 'Niveau trois interdit']);
    }

    public function test_show_emits_parent_id(): void
    {
        $parent = ItemCategory::factory()->create();
        $child = ItemCategory::factory()->create(['parent_id' => $parent->id]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->withApiKey()
            ->getJson('/api/admin/setting/item-category/show/' . $child->id);

        $response->assertSuccessful();
        $response->assertJsonPath('data.parent_id', $parent->id);

        $top = $this->actingAs($this->admin(), 'sanctum')
            ->withApiKey()
            ->getJson('/api/admin/setting/item-category/show/' . $parent->id);

        $top->assertSuccessful();
        $top->assertJsonPath('data.parent_id', null);
    }

    public function test_update_can_set_then_clear_parent(): void
    {
        $parent = ItemCategory::factory()->create();
        $category = ItemCategory::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->withApiKey()
            ->patchJson('/api/admin/setting/item-category/' . $category->id, [
                'name'      => $category->name,
                'status'    => 5,
                'parent_id' => $parent->id,
            ])
            ->assertSuccessful();

        $this->assertSame((int) $parent->id, (int) $category->fresh()->parent_id);

        $this->actingAs($this->admin(), 'sanctum')
            ->withApiKey()
            ->patchJson('/api/admin/setting/item-category/' . $category->id, [
                'name'      => $category->name,
                'status'    => 5,
                'parent_id' => null,
            ])
            ->assertSuccessful();

        $this->assertNull($category->fresh()->parent_id);
    }
}
