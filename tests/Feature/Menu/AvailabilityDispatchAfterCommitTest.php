<?php

namespace Tests\Feature\Menu;

use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Sentinel — CV1-V1-CLOSEOUT-001 T-CENT-AVAIL-DISPATCH-01.
 *
 * Verrouille l'invariant FoodKing #4 (dispatch-after-commit) sur
 * AvailabilityService. Avant ce fix, dispatchEvent() émettait
 * event(...) DANS une transaction → si rollback, event perdu.
 *
 * Référence audit: reports/audit/CV1_AUDIT_AXE1_CATALOG_CENTRALIZATION_2026-05-02.md
 * Source synthèse: reports/audit/CV1_V1_CLOSEOUT_MASTER_SYNTHESIS_2026-05-02.md §2 P0 #2
 */
class AvailabilityDispatchAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_outside_transaction_emits_event_immediately(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $svc = app(AvailabilityService::class);

        $svc->toggle($item->id, $branch->id, false, 'rupture_test');

        Event::assertDispatched(ItemAvailabilityChanged::class, 1);
    }

    public function test_toggle_in_committed_outer_transaction_emits_event_after_commit(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $svc = app(AvailabilityService::class);

        DB::transaction(function () use ($svc, $item, $branch) {
            $svc->toggle($item->id, $branch->id, false, 'rupture_test');
        });

        Event::assertDispatched(ItemAvailabilityChanged::class, 1);
    }

    public function test_toggle_in_rollbacked_outer_transaction_does_not_emit_event(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $svc = app(AvailabilityService::class);

        try {
            DB::transaction(function () use ($svc, $item, $branch) {
                $svc->toggle($item->id, $branch->id, false, 'rupture_test');
                throw new \RuntimeException('rollback by test');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }
}
