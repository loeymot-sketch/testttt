<?php

namespace Tests\Feature\Composer;

use App\Enums\EventType;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Item;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Composer\ComposerProfileService;
use App\Services\Composer\ComposerStepService;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ComposerPublishSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_scoped_publish_invalidates_kiosk_cache_and_persists_catalog_outbox(): void
    {
        Queue::fake();

        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $otherBranch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $profile = $this->profileForBranch($branch->id);
        $snapshot = app(MenuSnapshot::class);
        $before = $snapshot->current($branch->id);
        $otherBefore = $snapshot->current($otherBranch->id);
        Cache::put("kiosk.menu.branch.{$branch->id}", ['stale' => true], 120);
        Cache::put("kiosk.menu.branch.{$otherBranch->id}", ['stale' => true], 120);

        app(ComposerProfileService::class)->publish($profile);

        $this->assertFalse(Cache::has("kiosk.menu.branch.{$branch->id}"));
        $this->assertTrue(Cache::has("kiosk.menu.branch.{$otherBranch->id}"));
        $this->assertGreaterThan($before, $snapshot->current($branch->id));
        $this->assertSame($otherBefore, $snapshot->current($otherBranch->id));

        $row = $this->catalogRow($profile, $branch);
        $this->assertSame('published', $row->payload['change_type']);
        $this->assertSame('composer_profile', $row->payload['entity_type']);
        $this->assertSame((int) $profile->item_id, (int) $row->payload['payload_diff']['item_id']);
        $this->assertTrue((bool) $row->payload['payload_diff']['is_published']);
        $this->assertSame(['private-branch.' . $branch->id], json_decode($row->channel, true));
    }

    public function test_global_publish_persists_outbox_for_active_branches_only(): void
    {
        Queue::fake();

        $branchA = Branch::factory()->create(['status' => Status::ACTIVE]);
        $branchB = Branch::factory()->create(['status' => Status::ACTIVE]);
        $inactiveBranch = Branch::factory()->create(['status' => Status::INACTIVE]);
        $profile = $this->profileForBranch(null);

        Cache::put("kiosk.menu.branch.{$branchA->id}", ['stale' => true], 120);
        Cache::put("kiosk.menu.branch.{$branchB->id}", ['stale' => true], 120);
        Cache::put("kiosk.menu.branch.{$inactiveBranch->id}", ['stale' => true], 120);

        app(ComposerProfileService::class)->publish($profile);

        $this->assertFalse(Cache::has("kiosk.menu.branch.{$branchA->id}"));
        $this->assertFalse(Cache::has("kiosk.menu.branch.{$branchB->id}"));
        $this->assertFalse(Cache::has("kiosk.menu.branch.{$inactiveBranch->id}"));

        $rows = DomainEvent::query()
            ->where('event_type', EventType::CATALOG_CHANGED)
            ->where('aggregate_type', 'composer_profile')
            ->where('aggregate_id', $profile->id)
            ->orderBy('branch_id')
            ->get();

        $this->assertSame([$branchA->id, $branchB->id], $rows->pluck('branch_id')->all());
        $this->assertSame(['published', 'published'], $rows->map(fn (DomainEvent $row): string => $row->payload['change_type'])->all());
    }

    public function test_unpublish_invalidates_cache_and_persists_catalog_outbox(): void
    {
        Queue::fake();

        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $profile = $this->profileForBranch($branch->id, true);
        Cache::put("kiosk.menu.branch.{$branch->id}", ['stale' => true], 120);

        app(ComposerProfileService::class)->unpublish($profile);

        $this->assertFalse(Cache::has("kiosk.menu.branch.{$branch->id}"));

        $row = $this->catalogRow($profile, $branch);
        $this->assertSame('unpublished', $row->payload['change_type']);
        $this->assertFalse((bool) $row->payload['payload_diff']['is_published']);
    }

    public function test_published_step_mutation_is_rejected_until_draft_is_published(): void
    {
        Queue::fake();

        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $profile = $this->profileForBranch($branch->id, true);
        $step = ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'cuisson',
            'label' => 'Cuisson',
        ]);
        Cache::put("kiosk.menu.branch.{$branch->id}", ['stale' => true], 120);

        try {
            app(ComposerStepService::class)->update($step, [
                'step_key' => 'cuisson',
                'label' => 'Choix cuisson',
                'source_type' => 'item_attribute',
                'min_select' => 1,
                'max_select' => 1,
                'position' => 1,
            ]);
            $this->fail('A published wizard must not mutate till steps in place.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('steps', $exception->errors());
        }

        $this->assertTrue(Cache::has("kiosk.menu.branch.{$branch->id}"));
        $this->assertSame('Cuisson', $step->fresh()->label);

        $draft = app(ComposerProfileService::class)->update($profile, [
            'template' => $profile->template,
            'version' => $profile->version,
            'steps' => [[
                'step_key' => 'cuisson',
                'label' => 'Choix cuisson',
                'source_type' => 'item_attribute',
                'min_select' => 0,
                'max_select' => 1,
                'position' => 1,
                'is_active' => true,
                'visible_on' => ['pos', 'kiosk'],
            ]],
        ]);
        $this->assertFalse((bool) $draft->is_published);

        app(ComposerProfileService::class)->publish($draft);

        $this->assertFalse(Cache::has("kiosk.menu.branch.{$branch->id}"));
        $row = $this->catalogRow($draft, $branch);
        $this->assertSame('published', $row->payload['change_type']);
    }

    private function profileForBranch(?int $branchId, bool $published = false): ItemWizardProfile
    {
        $profile = ItemWizardProfile::factory()->create([
            'item_id' => Item::factory(),
            'branch_id_scope' => $branchId,
            'is_published' => $published,
            'published_at' => $published ? now() : null,
        ]);

        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'optional_choices',
            'label' => 'Optional choices',
            'source_type' => 'item_attribute',
            'source_ref' => '',
            'min_select' => 0,
            'max_select' => 1,
            'position' => 0,
            'is_active' => true,
        ]);

        return $profile;
    }

    private function catalogRow(ItemWizardProfile $profile, Branch $branch): DomainEvent
    {
        return DomainEvent::query()
            ->where('event_type', EventType::CATALOG_CHANGED)
            ->where('aggregate_type', 'composer_profile')
            ->where('aggregate_id', $profile->id)
            ->where('branch_id', $branch->id)
            ->latest('id')
            ->firstOrFail();
    }
}
