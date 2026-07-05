<?php

namespace Tests\Feature\Availability;

use App\Models\Branch;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [SELF-AUDIT R5 P2 2026-07-05] Le cron de reset du quota journalier zéro-tait daily_consumed_qty mais ne
 * REMETTAIT PAS is_available=true → un article auto-86'd par le quota (out_of_stock) restait EN RUPTURE
 * chaque jour APRÈS le premier. Ce test verrouille : le reset ré-active les 86 auto-quota ET préserve les
 * 86 MANUELS (supplier_issue / cron stock_rupture).
 */
class ResetStaleDailyQuotaReenableTest extends TestCase
{
    use RefreshDatabase;

    private function availabilityRow(string $reason, int $consumed = 5): int
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        return (int) DB::table('item_branch_availability')->insertGetId([
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'is_available' => false,
            'unavailable_reason' => $reason,
            'unavailable_since' => now()->subDays(1),
            'max_daily_qty' => 5,
            'daily_consumed_qty' => $consumed,
            'daily_reset_at' => now()->subDay()->toDateString(), // périmé
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }

    /** @test — un article auto-86'd (out_of_stock) périmé est RÉ-ACTIVÉ par le reset. */
    public function quota_auto_86_is_reenabled_on_reset(): void
    {
        $id = $this->availabilityRow('out_of_stock');

        Artisan::call('foodking:availability:reset-stale-quota');

        $this->assertDatabaseHas('item_branch_availability', [
            'id' => $id,
            'daily_consumed_qty' => 0,
            'is_available' => 1,
            'unavailable_reason' => null,
        ]);
    }

    /** @test — un 86 MANUEL (supplier_issue) périmé garde son indisponibilité (compteur remis à 0). */
    public function manual_86_is_preserved_on_reset(): void
    {
        $id = $this->availabilityRow('supplier_issue');

        Artisan::call('foodking:availability:reset-stale-quota');

        $this->assertDatabaseHas('item_branch_availability', [
            'id' => $id,
            'daily_consumed_qty' => 0,      // le compteur est bien remis à zéro
            'is_available' => 0,      // MAIS l'article reste 86'd manuellement
            'unavailable_reason' => 'supplier_issue',
        ]);
    }
}
