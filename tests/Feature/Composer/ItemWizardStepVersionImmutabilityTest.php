<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStepVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sentinel: ItemWizardStepVersion is insert-only by contract.
 * Aligned with stock_movements + composition_snapshot patterns.
 */
class ItemWizardStepVersionImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_method_throws_runtime_exception(): void
    {
        $profile = $this->createProfile();
        $version = ItemWizardStepVersion::create([
            'profile_id' => $profile->id,
            'version' => 1,
            'snapshot' => [['step_key' => 'a']],
            'published_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/insert-only/i');

        $version->update(['snapshot' => [['step_key' => 'modified']]]);
    }

    public function test_cascade_delete_via_profile_removes_versions(): void
    {
        $profile = $this->createProfile();
        ItemWizardStepVersion::create([
            'profile_id' => $profile->id,
            'version' => 1,
            'snapshot' => [],
            'published_at' => now(),
        ]);
        ItemWizardStepVersion::create([
            'profile_id' => $profile->id,
            'version' => 2,
            'snapshot' => [],
            'published_at' => now(),
        ]);

        $this->assertSame(2, ItemWizardStepVersion::where('profile_id', $profile->id)->count());

        $profile->delete();

        $this->assertSame(0, ItemWizardStepVersion::where('profile_id', $profile->id)->count());
    }

    public function test_snapshot_is_cast_to_array_on_read(): void
    {
        $profile = $this->createProfile();
        $version = ItemWizardStepVersion::create([
            'profile_id' => $profile->id,
            'version' => 1,
            'snapshot' => [
                ['step_key' => 'viande', 'min_select' => 1, 'max_select' => 1],
                ['step_key' => 'boisson', 'min_select' => 1, 'max_select' => 1],
            ],
            'published_at' => now(),
        ]);

        $version->refresh();

        $this->assertIsArray($version->snapshot);
        $this->assertCount(2, $version->snapshot);
        $this->assertSame('viande', $version->snapshot[0]['step_key']);
    }

    private function createProfile(): ItemWizardProfile
    {
        return ItemWizardProfile::factory()->create([
            'item_id' => Item::factory(),
        ]);
    }
}
