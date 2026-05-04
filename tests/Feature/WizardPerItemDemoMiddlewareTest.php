<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\User;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WizardPerItemDemoMiddlewareTest extends TestCase
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

    public function test_per_item_composer_profile_is_hidden_when_demo_flag_is_disabled(): void
    {
        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', false);

        $item = Item::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/items/{$item->id}/profile")
            ->assertNotFound();
    }

    public function test_per_item_composer_profile_is_accessible_when_demo_flag_is_enabled(): void
    {
        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', true);

        $item = Item::factory()->create();
        ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'item_category_id' => null,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/items/{$item->id}/profile")
            ->assertOk();
    }

    public function test_category_composer_profile_stays_open_when_demo_flag_is_disabled(): void
    {
        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', false);

        $category = ItemCategory::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/categories/{$category->id}/profile")
            ->assertNotFound();
    }
}
