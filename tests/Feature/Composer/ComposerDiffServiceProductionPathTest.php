<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\ItemWizardStepVersion;
use App\Models\User;
use App\Services\Composer\ComposerDiffService;
use App\Services\Composer\ComposerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests the production code path of ComposerDiffService:
 * with REAL persisted ItemWizardStepVersion rows (not setAttribute() in-memory injection).
 *
 * These tests prove that the false-positive silent behaviour
 * (R1 of ULTRA_REVIEW_GLOBAL_CATALOG_TREE_2026-05-03) is eliminated.
 */
class ComposerDiffServiceProductionPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Sanctum::actingAs($this->adminUser(), ['*']);
        Queue::fake();
    }

    public function test_diff_against_real_persisted_snapshot_detects_real_changes(): void
    {
        $profile = $this->createProfileWithSteps(3);
        $published = app(ComposerProfileService::class)->publish($profile);
        $step = $published->steps->firstWhere('step_key', 'step_2');

        $step->update(['max_select' => 2]);

        $diff = app(ComposerDiffService::class)->diff($published->fresh('steps'));

        $this->assertFalse($diff['is_clean']);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertCount(1, $diff['modified']);
        $this->assertSame('step_2', $diff['modified'][0]['step_key']);
        $this->assertSame(['max_select'], $diff['modified'][0]['changed_fields']);
    }

    public function test_diff_for_unpublished_profile_returns_consistent_structure(): void
    {
        $profile = $this->createProfileWithSteps(5);

        $diff = app(ComposerDiffService::class)->diff($profile);

        $this->assertFalse($diff['is_clean']);
        $this->assertNull($diff['published_version']);
        $this->assertCount(5, $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame([], $diff['modified']);
    }

    public function test_diff_after_two_publishes_compares_against_latest_version_only(): void
    {
        $profile = $this->createProfileWithSteps(3);
        $firstPublish = app(ComposerProfileService::class)->publish($profile);
        $firstPublishedVersion = (int) $firstPublish->version;

        $stepFour = ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'step_4',
            'label' => 'Step 4',
            'source_type' => 'item_attribute',
            'source_ref' => '',
            'min_select' => 0,
            'max_select' => 1,
            'position' => 4,
            'is_active' => true,
        ]);
        $secondPublish = app(ComposerProfileService::class)->publish($firstPublish);
        $secondPublishedVersion = (int) $secondPublish->version;

        $stepFour->update(['max_select' => 2]);

        $diff = app(ComposerDiffService::class)->diff($secondPublish->fresh('steps'));
        $versions = ItemWizardStepVersion::where('profile_id', $profile->id)
            ->orderBy('version')
            ->pluck('version')
            ->all();

        $this->assertSame([$firstPublishedVersion, $secondPublishedVersion], $versions);
        $this->assertFalse($diff['is_clean']);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertCount(1, $diff['modified']);
        $this->assertSame('step_4', $diff['modified'][0]['step_key']);
        $this->assertSame(['max_select'], $diff['modified'][0]['changed_fields']);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        return $admin;
    }

    private function createProfileWithSteps(int $stepCount): ItemWizardProfile
    {
        $profile = ItemWizardProfile::factory()->create([
            'item_id' => Item::factory(),
        ]);

        for ($position = 1; $position <= $stepCount; $position++) {
            ItemWizardStep::factory()->create([
                'profile_id' => $profile->id,
                'step_key' => 'step_' . $position,
                'label' => 'Step ' . $position,
                'source_type' => 'item_attribute',
                'source_ref' => '',
                'min_select' => 0,
                'max_select' => 1,
                'position' => $position,
                'is_active' => true,
            ]);
        }

        return $profile;
    }
}
