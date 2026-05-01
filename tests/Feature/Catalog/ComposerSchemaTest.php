<?php

namespace Tests\Feature\Catalog;

use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ComposerSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_steps_are_ordered_and_publish_bumps_version(): void
    {
        $this->assertTrue(Schema::hasTable('item_wizard_profiles'));
        $this->assertTrue(Schema::hasTable('item_wizard_steps'));

        $profile = ItemWizardProfile::factory()->create([
            'template' => 'sandwich',
            'version' => 1,
        ]);

        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'sauce',
            'label' => 'Sauce',
            'source_type' => 'extra_group',
            'source_ref' => 'sauces',
            'min_select' => 0,
            'max_select' => 2,
            'position' => 20,
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'crudites',
            'label' => 'Crudites',
            'source_type' => 'item_attribute',
            'source_ref' => 'crudites',
            'min_select' => 0,
            'max_select' => 3,
            'position' => 10,
        ]);

        $this->assertSame(['crudites', 'sauce'], $profile->fresh()->steps->pluck('step_key')->all());

        $profile->publish();
        $this->assertTrue($profile->fresh()->is_published);
        $this->assertSame(2, $profile->fresh()->version);
        $this->assertNotNull($profile->fresh()->published_at);

        $profile->unpublish();
        $this->assertFalse($profile->fresh()->is_published);
        $this->assertSame(3, $profile->fresh()->version);
    }

    public function test_step_rejects_invalid_selection_bounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ItemWizardStep::factory()->create([
            'min_select' => 3,
            'max_select' => 1,
        ]);
    }
}
