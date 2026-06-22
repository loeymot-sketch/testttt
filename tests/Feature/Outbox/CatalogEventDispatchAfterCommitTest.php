<?php

namespace Tests\Feature\Outbox;

use App\Events\CategoryCreated;
use App\Events\CategoryDeleted;
use App\Events\CategoryUpdated;
use App\Events\ItemCreated;
use App\Events\ItemDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Sentinel — Mission #1 Vague 1 action 1.6.
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md §A.2 #7
 * Plan : plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md task 1.6
 */
class CatalogEventDispatchAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    /** @group dispatch_after_commit_invariant */
    public function test_catalog_crud_events_not_dispatched_when_transaction_roll_back(): void
    {
        Event::fake([
            ItemCreated::class,
            ItemDeleted::class,
            CategoryCreated::class,
            CategoryUpdated::class,
            CategoryDeleted::class,
        ]);

        DB::beginTransaction();

        ItemCreated::dispatch(999001, 1);
        ItemDeleted::dispatch(999002, 1);
        CategoryCreated::dispatch(999003, 1);
        CategoryUpdated::dispatch(999004, 1);
        CategoryDeleted::dispatch(999005, 1);

        DB::rollBack();

        Event::assertNotDispatched(ItemCreated::class);
        Event::assertNotDispatched(ItemDeleted::class);
        Event::assertNotDispatched(CategoryCreated::class);
        Event::assertNotDispatched(CategoryUpdated::class);
        Event::assertNotDispatched(CategoryDeleted::class);
    }

    /** @group dispatch_after_commit_invariant */
    public function test_catalog_crud_events_dispatched_after_successful_commit(): void
    {
        Event::fake([
            ItemCreated::class,
            ItemDeleted::class,
            CategoryCreated::class,
            CategoryUpdated::class,
            CategoryDeleted::class,
        ]);

        DB::transaction(function (): void {
            ItemCreated::dispatch(999011, 1);
            ItemDeleted::dispatch(999012, 1);
            CategoryCreated::dispatch(999013, 1);
            CategoryUpdated::dispatch(999014, 1);
            CategoryDeleted::dispatch(999015, 1);
        });

        Event::assertDispatched(ItemCreated::class);
        Event::assertDispatched(ItemDeleted::class);
        Event::assertDispatched(CategoryCreated::class);
        Event::assertDispatched(CategoryUpdated::class);
        Event::assertDispatched(CategoryDeleted::class);
    }
}
