<?php

namespace Tests\Feature\Services;

use App\Enums\Status;
use App\Models\ItemCategory;
use App\Services\ItemCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [AUDIT 2026-07-13 P3 — Finding 2] La garde anti-orphelin de
 * ItemCategoryService::destroy doit refuser la suppression d'une catégorie
 * qui possède des SOUS-CATÉGORIES non-supprimées (sinon enfant orphelin :
 * parent_id pointant vers une catégorie soft-deleted), en plus de la garde
 * items directs déjà existante (P1-C). Une feuille vide reste supprimable.
 */
class ItemCategoryDestroyGuardTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ItemCategoryService
    {
        return app(ItemCategoryService::class);
    }

    public function test_destroy_rejects_category_with_active_children(): void
    {
        $parent = ItemCategory::forceCreate([
            'name' => 'Parent', 'slug' => 'parent-'.uniqid(), 'status' => Status::ACTIVE, 'sort' => 1,
        ]);
        ItemCategory::forceCreate([
            'name' => 'Child', 'slug' => 'child-'.uniqid(), 'parent_id' => $parent->id,
            'status' => Status::ACTIVE, 'sort' => 1,
        ]);

        try {
            $this->service()->destroy($parent);
            $this->fail('destroy() aurait dû lever une exception (sous-catégorie présente).');
        } catch (\Exception $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('sous-catégorie', $e->getMessage());
        }

        // Le parent ne doit PAS être supprimé.
        $this->assertDatabaseHas('item_categories', ['id' => $parent->id, 'deleted_at' => null]);
    }

    public function test_destroy_allows_empty_leaf(): void
    {
        $leaf = ItemCategory::forceCreate([
            'name' => 'Leaf', 'slug' => 'leaf-'.uniqid(), 'status' => Status::ACTIVE, 'sort' => 1,
        ]);

        $this->service()->destroy($leaf);

        $this->assertSoftDeleted('item_categories', ['id' => $leaf->id]);
    }

    public function test_destroy_ignores_already_deleted_children(): void
    {
        $parent = ItemCategory::forceCreate([
            'name' => 'Parent2', 'slug' => 'parent2-'.uniqid(), 'status' => Status::ACTIVE, 'sort' => 1,
        ]);
        $child = ItemCategory::forceCreate([
            'name' => 'Child2', 'slug' => 'child2-'.uniqid(), 'parent_id' => $parent->id,
            'status' => Status::ACTIVE, 'sort' => 1,
        ]);
        $child->delete(); // sous-catégorie déjà soft-deleted → ne bloque plus.

        $this->service()->destroy($parent);

        $this->assertSoftDeleted('item_categories', ['id' => $parent->id]);
    }
}
