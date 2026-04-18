<?php

namespace Tests\Feature\Services;

use App\Models\ItemCategory;
use App\Services\ItemCategoryHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemCategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_depth_2_enforced(): void
    {
        $root = ItemCategory::factory()->create([
            'name' => 'A',
            'slug' => 'a-root',
            'status' => 1,
        ]);
        $child = ItemCategory::factory()->create([
            'parent_id' => $root->id,
            'name' => 'B',
            'slug' => 'b-child',
            'status' => 1,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hierarchie categorie limitee a deux niveaux');

        app(ItemCategoryHierarchyService::class)->validateParent((int) $child->id);
    }
}
