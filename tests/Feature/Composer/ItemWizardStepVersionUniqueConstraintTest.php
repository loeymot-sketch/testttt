<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStepVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sentinel: (profile_id, version) is unique to prevent double-snapshot of the same profile version.
 */
class ItemWizardStepVersionUniqueConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_profile_version_blocks_duplicate(): void
    {
        $profile = $this->createProfile();

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

    public function test_same_version_for_different_profiles_is_allowed(): void
    {
        $profileA = $this->createProfile();
        $profileB = $this->createProfile();

        ItemWizardStepVersion::create([
            'profile_id' => $profileA->id,
            'version' => 1,
            'snapshot' => [],
            'published_at' => now(),
        ]);

        $created = ItemWizardStepVersion::create([
            'profile_id' => $profileB->id,
            'version' => 1,
            'snapshot' => [],
            'published_at' => now(),
        ]);

        $this->assertNotNull($created);
    }

    private function createProfile(): ItemWizardProfile
    {
        return ItemWizardProfile::factory()->create([
            'item_id' => Item::factory(),
        ]);
    }
}
