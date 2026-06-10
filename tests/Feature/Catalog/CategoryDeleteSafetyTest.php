<?php

namespace Tests\Feature\Catalog;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [GOAL CMS GESTION 2026-06-10 — Wave C1, T-C1.2 — RED P1-3]
 *
 * Delete-safety catégorie :
 *  - le destroy historique entourait un SOFT-delete de `SET FOREIGN_KEY_CHECKS=0`
 *    (cargo-cult dangereux : neutralise cascade/null-on-delete si un vrai DELETE
 *    s'y glisse un jour) → INTERDIT, vérifié par capture des requêtes.
 *  - guard items actifs (CAT-DEL-01, existant) : régression verrouillée.
 *  - guard ENFANTS : soft-delete d'un parent laisserait des sous-catégories
 *    orphelines (parent_id dangling) → 409.
 *  - guard WIZARD PROFILE catégorie : soft-delete laisserait un profil publié
 *    orphelin (le cascadeOnDelete ne fire que sur hard delete) → 409.
 */
class CategoryDeleteSafetyTest extends TestCase
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

    public function test_destroy_with_active_items_is_blocked(): void
    {
        $category = ItemCategory::factory()->create();
        Item::factory()->create(['item_category_id' => $category->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->withApiKey()
            ->deleteJson('/api/admin/setting/item-category/' . $category->id)
            ->assertStatus(409);

        $this->assertNull($category->fresh()->deleted_at);
    }

    public function test_destroy_with_children_is_blocked(): void
    {
        $parent = ItemCategory::factory()->create();
        ItemCategory::factory()->create(['parent_id' => $parent->id]);

        $this->actingAs($this->admin(), 'sanctum')
            ->withApiKey()
            ->deleteJson('/api/admin/setting/item-category/' . $parent->id)
            ->assertStatus(409);

        $this->assertNull($parent->fresh()->deleted_at);
    }

    public function test_destroy_with_published_wizard_profile_is_blocked(): void
    {
        $category = ItemCategory::factory()->create();
        ItemWizardProfile::factory()->create([
            'item_id'          => null,
            'item_category_id' => $category->id,
            'is_published'     => true,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->withApiKey()
            ->deleteJson('/api/admin/setting/item-category/' . $category->id)
            ->assertStatus(409);

        $this->assertNull($category->fresh()->deleted_at);
    }

    public function test_destroy_leaf_succeeds_without_disabling_foreign_key_checks(): void
    {
        $category = ItemCategory::factory()->create();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->withApiKey()
            ->deleteJson('/api/admin/setting/item-category/' . $category->id)
            ->assertSuccessful();

        $this->assertNotNull($category->fresh()->deleted_at, 'leaf category should be soft-deleted');

        $offenders = array_filter(
            $statements,
            fn (string $sql): bool => stripos($sql, 'FOREIGN_KEY_CHECKS') !== false
                || stripos($sql, 'foreign_keys') !== false
        );
        $this->assertSame([], array_values($offenders), 'destroy must not toggle FK checks');
    }
}
