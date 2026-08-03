<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Composer\ComposerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComposerProfileServicePublishableCategoryTest extends TestCase
{
    use RefreshDatabase;

    private ComposerProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->service = app(ComposerProfileService::class);
    }

    public function test_publishable_category_profile_does_not_require_item_choice_resolution(): void
    {
        $category = ItemCategory::factory()->create();
        $profile = ItemWizardProfile::factory()->forCategory($category)->create();
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'source_type' => 'item_attribute',
            'source_ref' => 'missing-attribute',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
        ]);

        $published = $this->service->publish($profile);

        $this->assertTrue((bool) $published->is_published);
        $this->assertDatabaseHas('item_wizard_step_versions', [
            'profile_id' => $profile->id,
            'version' => $published->version,
        ]);
    }

    public function test_publishable_per_item_profile_still_validates_choice_availability(): void
    {
        $item = Item::factory()->create();
        $profile = ItemWizardProfile::factory()->create(['item_id' => $item->id]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'source_type' => 'item_attribute',
            'source_ref' => 'missing-attribute',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->service->publish($profile);
    }
}
