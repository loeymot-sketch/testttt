<?php

namespace Tests\Feature\Composer;

use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\ItemWizardStepVersion;
use App\Services\Composer\ComposerDiffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComposerDiffServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
    }

    public function test_never_published_profile_marks_all_steps_as_added_and_empty_profile_as_clean(): void
    {
        $emptyProfile = ItemWizardProfile::factory()->create([
            'is_published' => false,
            'version' => 1,
        ]);

        $emptyDiff = $this->service()->diff($emptyProfile);

        $this->assertTrue($emptyDiff['is_clean']);
        $this->assertNull($emptyDiff['published_version']);
        $this->assertSame([], $emptyDiff['added']);
        $this->assertSame([], $emptyDiff['removed']);
        $this->assertSame([], $emptyDiff['modified']);

        $profile = ItemWizardProfile::factory()->create([
            'is_published' => false,
            'version' => 1,
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'crudites',
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'drink',
        ]);

        $diff = $this->service()->diff($profile);

        $this->assertFalse($diff['is_clean']);
        $this->assertNull($diff['published_version']);
        $this->assertCount(2, $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame([], $diff['modified']);
    }

    public function test_published_profile_with_identical_draft_is_clean(): void
    {
        $profile = ItemWizardProfile::factory()->create([
            'is_published' => true,
            'version' => 2,
        ]);
        $step = ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'crudites',
            'max_select' => 3,
        ]);

        $this->attachPublishedSnapshot($profile, [$this->row($step)]);

        $diff = $this->service()->diff($profile);

        $this->assertTrue($diff['is_clean']);
        $this->assertSame(2, $diff['published_version']);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame([], $diff['modified']);
    }

    public function test_added_draft_step_is_reported(): void
    {
        $profile = ItemWizardProfile::factory()->create([
            'is_published' => true,
            'version' => 3,
        ]);
        $publishedStep = ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'crudites',
            'position' => 1,
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'drink',
            'position' => 2,
            'source_type' => 'addon',
            'addon_role' => 'drink',
        ]);

        $this->attachPublishedSnapshot($profile, [$this->row($publishedStep)]);

        $diff = $this->service()->diff($profile);

        $this->assertFalse($diff['is_clean']);
        $this->assertCount(1, $diff['added']);
        $this->assertSame('drink', $diff['added'][0]['step_key']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame([], $diff['modified']);
    }

    public function test_removed_published_step_is_reported(): void
    {
        $profile = ItemWizardProfile::factory()->create([
            'is_published' => true,
            'version' => 3,
        ]);
        $keptStep = ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'crudites',
        ]);
        $removedStep = $this->row(ItemWizardStep::factory()->make([
            'profile_id' => $profile->id,
            'step_key' => 'drink',
            'source_type' => 'addon',
            'addon_role' => 'drink',
        ]));

        $this->attachPublishedSnapshot($profile, [$this->row($keptStep), $removedStep]);

        $diff = $this->service()->diff($profile);

        $this->assertFalse($diff['is_clean']);
        $this->assertSame([], $diff['added']);
        $this->assertCount(1, $diff['removed']);
        $this->assertSame('drink', $diff['removed'][0]['step_key']);
        $this->assertSame([], $diff['modified']);
    }

    public function test_modified_max_select_is_reported(): void
    {
        $profile = ItemWizardProfile::factory()->create([
            'is_published' => true,
            'version' => 4,
        ]);
        $step = ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'sauces',
            'max_select' => 2,
        ]);
        $before = $this->row($step, ['max_select' => 1]);

        $this->attachPublishedSnapshot($profile, [$before]);

        $diff = $this->service()->diff($profile);

        $this->assertFalse($diff['is_clean']);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertCount(1, $diff['modified']);
        $this->assertSame('sauces', $diff['modified'][0]['step_key']);
        $this->assertSame(['max_select'], $diff['modified'][0]['changed_fields']);
    }

    public function test_modified_visible_on_and_addon_role_are_reported(): void
    {
        $profile = ItemWizardProfile::factory()->create([
            'is_published' => true,
            'version' => 5,
        ]);
        $step = ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'addon_choice',
            'source_type' => 'addon',
            'source_ref' => 'addons',
            'visible_on' => ['kiosk'],
            'addon_role' => 'side',
        ]);
        $before = $this->row($step, [
            'visible_on' => ['pos', 'kiosk'],
            'addon_role' => 'drink',
        ]);

        $this->attachPublishedSnapshot($profile, [$before]);

        $diff = $this->service()->diff($profile);

        $this->assertFalse($diff['is_clean']);
        $this->assertCount(1, $diff['modified']);
        $this->assertSame('addon_choice', $diff['modified'][0]['step_key']);
        $this->assertSame(['visible_on', 'addon_role'], $diff['modified'][0]['changed_fields']);
    }

    private function service(): ComposerDiffService
    {
        return app(ComposerDiffService::class);
    }

    private function attachPublishedSnapshot(ItemWizardProfile $profile, array $steps): ItemWizardStepVersion
    {
        return ItemWizardStepVersion::create([
            'profile_id' => $profile->id,
            'version' => $profile->version,
            'snapshot' => $steps,
            'published_at' => now(),
            'published_by_id' => null,
        ]);
    }

    private function row(ItemWizardStep $step, array $overrides = []): array
    {
        return array_merge([
            'id' => $step->id,
            'profile_id' => $step->profile_id,
            'step_key' => $step->step_key,
            'label' => $step->label,
            'source_type' => $step->source_type,
            'source_ref' => $step->source_ref,
            'source_item_attribute_id' => $step->source_item_attribute_id,
            'min_select' => $step->min_select,
            'max_select' => $step->max_select,
            'allow_repeat' => $step->allow_repeat,
            'visible_on' => $step->visible_on,
            'stockable_choices' => $step->stockable_choices,
            'position' => $step->position,
            'is_active' => $step->is_active,
            'addon_role' => $step->addon_role,
        ], $overrides);
    }
}
