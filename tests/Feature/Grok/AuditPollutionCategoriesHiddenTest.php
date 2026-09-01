<?php

namespace Tests\Feature\Grok;

use App\Enums\Status;
use App\Models\ItemCategory;
use App\Services\ItemCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditPollutionCategoriesHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_pollution_names_are_detected(): void
    {
        $this->assertTrue(ItemCategoryService::isAuditPollutionName('AUDIT-KIOSK-MULTI 1'));
        $this->assertTrue(ItemCategoryService::isAuditPollutionName('E2E Cat 1786616399744'));
        $this->assertTrue(ItemCategoryService::isAuditPollutionName('E2ECategory13511EDITED'));
        $this->assertFalse(ItemCategoryService::isAuditPollutionName('Sandwichs'));
        $this->assertFalse(ItemCategoryService::isAuditPollutionName('Tacos'));
        $this->assertTrue(ItemCategoryService::isAuditPollutionName('Aliquam'));
        $this->assertTrue(ItemCategoryService::isAuditPollutionName('Rerum'));
        $this->assertFalse(ItemCategoryService::isAuditPollutionName('Uber (technique)'));
        $this->assertTrue(ItemCategoryService::isInternalOpsCategoryName('Technique (interne — upsell)'));
        $this->assertFalse(ItemCategoryService::isInternalOpsCategoryName('Sandwichs'));
    }

    public function test_public_list_hides_audit_categories_keeps_real_ones(): void
    {
        ItemCategory::factory()->create(['name' => 'Sandwichs', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'AUDIT-KIOSK-MULTI', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'E2E Cat 1786616399744', 'status' => Status::ACTIVE]);

        $names = collect($this->getJson('/api/frontend/item-category?paginate=0')->json('data'))
            ->pluck('name')
            ->all();

        $this->assertContains('Sandwichs', $names);
        $this->assertNotContains('AUDIT-KIOSK-MULTI', $names);
        $this->assertNotContains('E2E Cat 1786616399744', $names);
    }

    public function test_admin_list_hides_audit_categories(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        ItemCategory::factory()->create(['name' => 'Tacos', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'TEST-orphan', 'status' => Status::ACTIVE]);

        $names = collect($this->getJson('/api/admin/setting/item-category?paginate=0')->json('data'))
            ->pluck('name')
            ->all();

        $this->assertContains('Tacos', $names);
        $this->assertNotContains('TEST-orphan', $names);
    }

    public function test_admin_list_hides_faker_latin_keeps_real_and_interne(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        ItemCategory::factory()->create(['name' => 'Sandwichs', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'Aliquam', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'Rerum', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'Uber (technique)', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'Technique (interne — upsell)', 'status' => Status::ACTIVE]);

        $names = collect($this->getJson('/api/admin/setting/item-category?paginate=0')->json('data'))
            ->pluck('name')
            ->all();

        $this->assertContains('Sandwichs', $names);
        $this->assertContains('Uber (technique)', $names);
        $this->assertContains('Technique (interne — upsell)', $names);
        $this->assertNotContains('Aliquam', $names);
        $this->assertNotContains('Rerum', $names);
    }

    public function test_total_menu_items_counts_only_customer_facing_active(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $sandwichs = ItemCategory::factory()->create(['name' => 'Sandwichs', 'status' => Status::ACTIVE]);
        $e2e = ItemCategory::factory()->create(['name' => 'E2E Cat X', 'status' => Status::ACTIVE]);
        $interne = ItemCategory::factory()->create(['name' => 'Technique (interne — upsell)', 'status' => Status::ACTIVE]);
        $aliquam = ItemCategory::factory()->create(['name' => 'Aliquam', 'status' => Status::ACTIVE]);

        \App\Models\Item::factory()->count(2)->create([
            'item_category_id' => $sandwichs->id,
            'status' => Status::ACTIVE,
        ]);
        \App\Models\Item::factory()->count(5)->create([
            'item_category_id' => $e2e->id,
            'status' => Status::ACTIVE,
        ]);
        \App\Models\Item::factory()->count(3)->create([
            'item_category_id' => $interne->id,
            'status' => Status::ACTIVE,
        ]);
        \App\Models\Item::factory()->create([
            'item_category_id' => $aliquam->id,
            'status' => Status::ACTIVE,
        ]);
        \App\Models\Item::factory()->create([
            'item_category_id' => $sandwichs->id,
            'status' => Status::INACTIVE,
        ]);

        $this->getJson('/api/admin/dashboard/total-menu-items')
            ->assertOk()
            ->assertJsonPath('data.total_menu_items', 2);
    }

    public function test_featured_carousel_hides_e2e_item_names(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $sandwichs = ItemCategory::factory()->create(['name' => 'Sandwichs', 'status' => Status::ACTIVE]);
        $real = \App\Models\Item::factory()->create([
            'name' => 'Big Burger',
            'item_category_id' => $sandwichs->id,
            'status' => Status::ACTIVE,
            'is_featured' => 5,
        ]);
        \App\Models\Item::factory()->create([
            'name' => 'E2E_PLAYWRIGHT_STUDIO_ITEM',
            'item_category_id' => $sandwichs->id,
            'status' => Status::ACTIVE,
            'is_featured' => 5,
        ]);

        $names = collect($this->getJson('/api/admin/dashboard/featured-items')->json('data'))
            ->pluck('name')
            ->all();

        $this->assertContains('Big Burger', $names);
        $this->assertNotContains('E2E_PLAYWRIGHT_STUDIO_ITEM', $names);
        $this->assertNotNull($real->id);
    }
}
