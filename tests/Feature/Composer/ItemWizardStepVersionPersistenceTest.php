<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\ItemWizardStepVersion;
use App\Models\User;
use App\Services\Composer\ComposerProfileService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ItemWizardStepVersionPersistenceTest extends TestCase
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

    public function test_publish_inserts_one_version_row_with_full_snapshot(): void
    {
        $profile = $this->createProfileWithSteps(3);

        $published = app(ComposerProfileService::class)->publish($profile);

        $version = ItemWizardStepVersion::where('profile_id', $profile->id)->first();
        $this->assertNotNull($version);
        $this->assertSame((int) $published->version, $version->version);
        $this->assertCount(3, $version->snapshot);
        $this->assertSame(now()->toDateString(), $version->published_at->toDateString());
        $this->assertSame(auth()->id(), $version->published_by_id);
    }

    public function test_subsequent_publish_inserts_additional_version_row(): void
    {
        $profile = $this->createProfileWithSteps(2);

        $firstPublish = app(ComposerProfileService::class)->publish($profile);
        $firstPublishedVersion = (int) $firstPublish->version;
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'step_3',
            'label' => 'Step 3',
            'position' => 3,
        ]);

        $secondPublish = app(ComposerProfileService::class)->publish($firstPublish);
        $secondPublishedVersion = (int) $secondPublish->version;

        $versions = ItemWizardStepVersion::where('profile_id', $profile->id)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertSame([$firstPublishedVersion, $secondPublishedVersion], $versions->pluck('version')->all());
        $this->assertCount(2, $versions->first()->snapshot);
        $this->assertCount(3, $versions->last()->snapshot);
    }

    public function test_unique_profile_version_constraint_blocks_duplicate(): void
    {
        $profile = $this->createProfileWithSteps(2);
        ItemWizardStepVersion::create([
            'profile_id' => $profile->id,
            'version' => 1,
            'snapshot' => [],
            'published_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        ItemWizardStepVersion::create([
            'profile_id' => $profile->id,
            'version' => 1,
            'snapshot' => [],
            'published_at' => now(),
        ]);
    }

    public function test_cascade_delete_removes_versions(): void
    {
        $profile = $this->createProfileWithSteps(2);
        ItemWizardStepVersion::create([
            'profile_id' => $profile->id,
            'version' => 1,
            'snapshot' => [],
            'published_at' => now(),
        ]);

        $profile->delete();

        $this->assertSame(0, ItemWizardStepVersion::where('profile_id', $profile->id)->count());
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
