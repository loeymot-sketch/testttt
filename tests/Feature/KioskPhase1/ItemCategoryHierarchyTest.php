<?php

namespace Tests\Feature\KioskPhase1;

use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 1.2 : hiérarchie catégories (2 niveaux max).
 */
class ItemCategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_category_has_no_parent_and_depth_zero(): void
    {
        $root = $this->makeCategory('Burgers');
        $this->assertNull($root->parent_id);
        $this->assertSame(0, $root->depth());
    }

    public function test_child_category_depth_is_one(): void
    {
        $root = $this->makeCategory('Burgers');
        $child = $this->makeCategory('Signature', $root->id);

        $child->load('parent');
        $this->assertSame($root->id, $child->parent_id);
        $this->assertSame(1, $child->depth());
    }

    public function test_can_attach_under_root(): void
    {
        $root = $this->makeCategory('Burgers');
        $this->assertTrue(ItemCategory::canAttachUnder($root));
    }

    public function test_cannot_attach_under_child_depth_guard(): void
    {
        $root = $this->makeCategory('Burgers');
        $child = $this->makeCategory('Signature', $root->id);

        $this->assertFalse(
            ItemCategory::canAttachUnder($child),
            'Empêche profondeur > 2 : on ne doit pas pouvoir attacher sous un enfant.'
        );
    }

    public function test_attach_under_null_is_always_allowed(): void
    {
        $this->assertTrue(ItemCategory::canAttachUnder(null));
    }

    public function test_children_relation_loads_descendants(): void
    {
        $root = $this->makeCategory('Burgers');
        $child1 = $this->makeCategory('Signature', $root->id);
        $child2 = $this->makeCategory('Veggie', $root->id);

        $root->load('children');
        $this->assertCount(2, $root->children);
        $this->assertTrue($root->children->pluck('id')->contains($child1->id));
        $this->assertTrue($root->children->pluck('id')->contains($child2->id));
    }

    public function test_deleting_parent_sets_child_parent_id_to_null(): void
    {
        // ON DELETE SET NULL est une contrainte SQL MySQL-native. SQLite
        // en mémoire ne l'active qu'avec PRAGMA foreign_keys=ON et si la
        // FK est déclarée inline dans CREATE TABLE (pas via ALTER TABLE —
        // ce que Laravel fait). On vérifie en MySQL uniquement.
        if (\DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite ne re-trigger pas ON DELETE SET NULL pour les FK ajoutées via ALTER TABLE.');
        }

        $root = $this->makeCategory('Burgers');
        $child = $this->makeCategory('Signature', $root->id);

        $root->forceDelete();

        $child->refresh();
        $this->assertNull($child->parent_id, 'ON DELETE SET NULL protège les enfants.');
    }

    private function makeCategory(string $name, ?int $parentId = null): ItemCategory
    {
        return ItemCategory::forceCreate([
            'parent_id'   => $parentId,
            'name'        => $name,
            'slug'        => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'description' => null,
            'status'      => 1,
            'sort'        => 1,
        ]);
    }
}
