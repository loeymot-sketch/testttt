<?php

namespace Tests\Feature\Grok;

use App\Enums\Status;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InactiveCategoryHiddenFromPublicShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_admin_can_still_open_inactive_category(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::factory()->create([
            'name' => 'Archive',
            'status' => Status::INACTIVE,
        ]);

        $this->getJson('/api/admin/setting/item-category/show/'.$category->id)->assertOk();
    }

    public function test_public_show_hides_inactive_category(): void
    {
        $category = ItemCategory::factory()->create([
            'name' => 'Archive',
            'slug' => 'archive-inactive-grok',
            'status' => Status::INACTIVE,
        ]);

        $response = $this->getJson('/api/frontend/item-category/show/'.$category->slug);
        $this->assertTrue(
            in_array($response->status(), [404, 422], true),
            'Une catégorie inactive ne doit plus sortir en JSON public.'
        );
    }
}
