<?php

namespace Tests\Feature\Availability;

use App\Models\Branch;
use App\Models\Item;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [quota-daily-reenable-cron-only 2026-07-22 / Vague 2 STOCK-HARDENING]
 *
 * The 00:05 ResetStaleDailyQuotaCommand cron is the ONLY thing that re-enabled
 * quota-auto-86 items after midnight — but the Cayenne box is off overnight, so a
 * cold morning boot NEVER ran it and quota-86'd items stayed blocked all day.
 *
 * AvailabilityService::reconcileStaleDailyQuota() is the lazy catch-up: the first
 * availability read of the day re-enables + zeroes the previous days' quota-auto-86
 * rows. This test locks: re-enable of 'out_of_stock', preservation of manual 86,
 * once-per-day idempotency guard, branch isolation, and the read-path hook.
 */
class LazyDailyQuotaReconcileTest extends TestCase
{
    use RefreshDatabase;

    private function staleRow(int $branchId, int $itemId, string $reason, bool $available = false): int
    {
        return (int) DB::table('item_branch_availability')->insertGetId([
            'branch_id' => $branchId,
            'item_id' => $itemId,
            'is_available' => $available,
            'unavailable_reason' => $available ? null : $reason,
            'unavailable_since' => $available ? null : now()->subDay(),
            'max_daily_qty' => 5,
            'daily_consumed_qty' => 5,
            'daily_reset_at' => now()->subDay()->toDateString(), // périmé (hier)
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }

    public function test_stale_quota_auto_86_is_reenabled_and_counter_reset(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $id = $this->staleRow($branch->id, $item->id, 'out_of_stock');

        $reenabled = app(AvailabilityService::class)->reconcileStaleDailyQuota($branch->id);

        $this->assertSame(1, $reenabled);
        $this->assertDatabaseHas('item_branch_availability', [
            'id' => $id,
            'is_available' => 1,
            'unavailable_reason' => null,
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);
    }

    public function test_manual_86_is_preserved_but_counter_still_reset(): void
    {
        $branch = Branch::factory()->create();
        foreach (['stock_rupture', 'out_of_stock_manual', 'supplier_issue'] as $reason) {
            $item = Item::factory()->create();
            $id = $this->staleRow($branch->id, $item->id, $reason);

            app(AvailabilityService::class)->reconcileStaleDailyQuota($branch->id);

            $this->assertDatabaseHas('item_branch_availability', [
                'id' => $id,
                'is_available' => 0,                    // reste 86'd manuellement
                'unavailable_reason' => $reason,        // raison préservée
                'daily_consumed_qty' => 0,              // compteur quand même remis à 0
                'daily_reset_at' => now()->toDateString(),
            ]);

            // Chaque branche/jour reconcilie une seule fois → purge le verrou pour l'itération suivante.
            Cache::flush();
        }
    }

    public function test_reconcile_is_idempotent_once_per_day(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $this->staleRow($branch->id, $item->id, 'out_of_stock');

        $first = app(AvailabilityService::class)->reconcileStaleDailyQuota($branch->id);
        $second = app(AvailabilityService::class)->reconcileStaleDailyQuota($branch->id);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'Second read of the day must be a guarded no-op.');
    }

    public function test_reconcile_is_branch_scoped(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $item = Item::factory()->create();
        $idA = $this->staleRow($branchA->id, $item->id, 'out_of_stock');
        $idB = $this->staleRow($branchB->id, $item->id, 'out_of_stock');

        app(AvailabilityService::class)->reconcileStaleDailyQuota($branchA->id);

        $this->assertDatabaseHas('item_branch_availability', ['id' => $idA, 'is_available' => 1]);
        // Branch B untouched — no cross-branch leak.
        $this->assertDatabaseHas('item_branch_availability', ['id' => $idB, 'is_available' => 0, 'unavailable_reason' => 'out_of_stock']);
    }

    public function test_read_path_snapshot_triggers_lazy_reconcile(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $this->staleRow($branch->id, $item->id, 'out_of_stock');

        // getBranchAvailabilitySnapshot is the POS / panel read entry — reading it
        // must catch up the stale quota row BEFORE returning the snapshot, so the
        // re-enabled item no longer appears unavailable.
        $snapshot = app(AvailabilityService::class)->getBranchAvailabilitySnapshot($branch->id);

        $this->assertSame([], $snapshot['items'], 'Re-enabled item must be absent from the unavailable snapshot.');
        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 1,
        ]);
    }

    public function test_same_day_row_is_untouched(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        // Today's row (not stale) — must NOT be reset.
        $id = (int) DB::table('item_branch_availability')->insertGetId([
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock',
            'unavailable_since' => now(),
            'max_daily_qty' => 5,
            'daily_consumed_qty' => 5,
            'daily_reset_at' => now()->toDateString(), // aujourd'hui
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reenabled = app(AvailabilityService::class)->reconcileStaleDailyQuota($branch->id);

        $this->assertSame(0, $reenabled);
        $this->assertDatabaseHas('item_branch_availability', [
            'id' => $id,
            'is_available' => 0,
            'daily_consumed_qty' => 5, // pas remis à zéro : quota du jour en cours
        ]);
    }
}
