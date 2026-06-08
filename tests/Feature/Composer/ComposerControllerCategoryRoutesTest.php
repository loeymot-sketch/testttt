<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\User;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComposerControllerCategoryRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seed(ComposerPermissionsMinimalSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    public function test_show_for_category_returns_404_when_no_profile(): void
    {
        $category = ItemCategory::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/categories/{$category->id}/profile")
            ->assertNotFound();
    }

    public function test_apply_template_to_empty_category_returns_422(): void
    {
        $category = ItemCategory::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/apply-template", [
                'template' => 'tacos',
            ])
            ->assertStatus(422);
    }

    // [GOAL_WIZARD_DYNAMIC W1 / GAP-E] The category composer builder must be able to
    // populate its source picker. Before this the frontend hard-coded empty source
    // arrays for category context (loadAvailableSources), so the owner could not pick
    // which attribute/extra/addon a page binds to when composing a CATEGORY wizard.
    public function test_available_sources_for_empty_category_returns_422(): void
    {
        $category = ItemCategory::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/categories/{$category->id}/available-sources")
            ->assertStatus(422);
    }

    public function test_available_sources_for_category_derives_from_representative_item(): void
    {
        $category = ItemCategory::factory()->create();
        Item::factory()->create(['item_category_id' => $category->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/categories/{$category->id}/available-sources")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['category_id', 'item_attribute', 'extra_group', 'addon']]);
    }

    public function test_apply_template_to_category_with_item_creates_profile_owned_by_category(): void
    {
        $category = ItemCategory::factory()->create();
        Item::factory()->create(['item_category_id' => $category->id]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/apply-template", [
                'template' => 'tacos',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.template', 'tacos');

        $profile = ItemWizardProfile::query()->where('item_category_id', $category->id)->first();
        $this->assertNotNull($profile);
        $this->assertNull($profile->item_id);
        $this->assertSame(6, $profile->steps()->count());
        $this->assertDatabaseHas('item_categories', [
            'id' => $category->id,
            'wizard_profile_id' => $profile->id,
        ]);
    }
}
