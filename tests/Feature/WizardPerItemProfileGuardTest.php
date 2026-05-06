<?php

namespace Tests\Feature;

use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\User;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WizardPerItemProfileGuardTest extends TestCase
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

    public function test_flag_off_blocks_item_owned_profile_update(): void
    {
        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', false);

        $profile = ItemWizardProfile::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/composer/profiles/{$profile->id}", $this->profilePayload($profile))
            ->assertNotFound();
    }

    public function test_flag_off_blocks_item_owned_profile_step_creation(): void
    {
        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', false);

        $profile = ItemWizardProfile::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$profile->id}/steps", $this->stepPayload())
            ->assertNotFound();
    }

    public function test_flag_off_blocks_item_owned_step_update(): void
    {
        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', false);

        $profile = ItemWizardProfile::factory()->create();
        $step = ItemWizardStep::factory()->create(['profile_id' => $profile->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/composer/steps/{$step->id}", $this->stepPayload(['label' => 'Blocked update']))
            ->assertNotFound();
    }

    public function test_flag_off_allows_category_owned_profile_update(): void
    {
        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', false);

        $profile = $this->categoryOwnedProfile();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/composer/profiles/{$profile->id}", $this->profilePayload($profile))
            ->assertSuccessful();
    }

    public function test_flag_off_allows_category_owned_profile_step_creation(): void
    {
        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', false);

        $profile = $this->categoryOwnedProfile();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$profile->id}/steps", $this->stepPayload())
            ->assertSuccessful();
    }

    public function test_flag_on_allows_item_owned_profile_update(): void
    {
        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', true);

        $profile = ItemWizardProfile::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/composer/profiles/{$profile->id}", $this->profilePayload($profile))
            ->assertSuccessful();
    }

    private function categoryOwnedProfile(): ItemWizardProfile
    {
        $category = ItemCategory::factory()->create();
        $profile = ItemWizardProfile::factory()->forCategory($category)->create();
        $category->update(['wizard_profile_id' => $profile->id]);

        return $profile;
    }

    private function profilePayload(ItemWizardProfile $profile): array
    {
        return [
            'template' => 'custom',
            'version' => (int) $profile->version,
        ];
    }

    private function stepPayload(array $overrides = []): array
    {
        return array_merge([
            'step_key' => 'sauce-choice',
            'label' => 'Sauce choice',
            'source_type' => 'item_attribute',
            'source_ref' => '',
            'min_select' => 0,
            'max_select' => 1,
            'allow_repeat' => false,
            'visible_on' => ['pos', 'kiosk'],
            'stockable_choices' => false,
            'position' => 1,
            'is_active' => true,
        ], $overrides);
    }
}
