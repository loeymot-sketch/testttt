<?php

namespace Tests\Feature\Composer;

use App\Events\ComposerProfileChanged;
use App\Models\Item;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Composer\ComposerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Sentinel — Mission #2 Vague 2 action 2.8 (Composer profile unpublish symmetry).
 *
 * Asserts that ComposerProfileService::unpublish:
 *   - flips is_published from true to false on the model.
 *   - dispatches ComposerProfileChanged with changeType='unpublished'.
 *   - on an already-unpublished profile: no crash; current impl still dispatches once (see test body).
 *   - includes the correct payload (item_id, version, branch id scope on the event).
 *   - persists the change in DB.
 *
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md §2.8.
 */
class ComposerProfileUnpublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ComposerProfileChanged::class]);
    }

    public function test_unpublish_flips_is_published_to_false(): void
    {
        $profile = $this->publishedProfile();

        app(ComposerProfileService::class)->unpublish($profile);

        $profile->refresh();
        $this->assertFalse((bool) $profile->is_published);
    }

    public function test_unpublish_dispatches_composer_profile_changed_with_unpublished_type(): void
    {
        $profile = $this->publishedProfile();

        $fresh = app(ComposerProfileService::class)->unpublish($profile);

        Event::assertDispatchedTimes(ComposerProfileChanged::class, 1);
        Event::assertDispatched(ComposerProfileChanged::class, function (ComposerProfileChanged $e) use ($fresh): bool {
            return $e->profileId === (int) $fresh->id
                && $e->changeType === 'unpublished'
                && $e->branchId === ($fresh->branch_id_scope !== null ? (int) $fresh->branch_id_scope : null)
                && ($e->payloadDiff['is_published'] ?? null) === false;
        });
    }

    public function test_unpublish_payload_contains_item_id_and_version(): void
    {
        $profile = $this->publishedProfile();

        $fresh = app(ComposerProfileService::class)->unpublish($profile);

        Event::assertDispatched(ComposerProfileChanged::class, function (ComposerProfileChanged $e) use ($fresh): bool {
            return ($e->payloadDiff['item_id'] ?? null) === (int) $fresh->item_id
                && ($e->payloadDiff['version'] ?? null) === (int) $fresh->version;
        });
    }

    public function test_unpublish_already_unpublished_profile_still_runs_but_dispatches_event(): void
    {
        // Note: the current implementation always dispatches ComposerProfileChanged on unpublish().
        // If idempotency is added later, update this test. For V1 we just check no crash + 1 event.
        $profile = $this->draftProfile();
        app(ComposerProfileService::class)->unpublish($profile);

        Event::assertDispatchedTimes(ComposerProfileChanged::class, 1);
        $profile->refresh();
        $this->assertFalse((bool) $profile->is_published);
    }

    public function test_unpublish_persists_in_db_and_returns_fresh_model(): void
    {
        $profile = $this->publishedProfile();

        $returned = app(ComposerProfileService::class)->unpublish($profile);

        $this->assertSame((int) $profile->id, (int) $returned->id);
        $this->assertFalse((bool) $returned->is_published);
        $this->assertSame(0, ItemWizardProfile::query()
            ->where('id', $profile->id)
            ->where('is_published', true)
            ->count());
    }

    private function publishedProfile(): ItemWizardProfile
    {
        $item = Item::factory()->create(['channels' => ['pos']]);
        $profile = ItemWizardProfile::query()->create([
            'item_id' => $item->id,
            'template' => 'custom',
            'branch_id_scope' => null,
            'version' => 1,
            'is_published' => true,
            'published_at' => now(),
        ]);

        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'optional_choices',
            'label' => 'Optional choices',
            'source_type' => 'item_attribute',
            'source_ref' => '',
            'min_select' => 1,
            'max_select' => 1,
            'position' => 1,
            'is_active' => true,
            'visible_on' => ['pos', 'kiosk'],
        ]);

        return $profile->fresh('steps');
    }

    private function draftProfile(): ItemWizardProfile
    {
        $item = Item::factory()->create(['channels' => ['pos']]);

        return ItemWizardProfile::query()->create([
            'item_id' => $item->id,
            'template' => 'custom',
            'branch_id_scope' => null,
            'version' => 1,
            'is_published' => false,
        ]);
    }
}
