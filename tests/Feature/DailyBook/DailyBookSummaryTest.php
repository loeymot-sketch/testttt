<?php

namespace Tests\Feature\DailyBook;

use App\Models\DailyBookEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W4] Résumé mensuel : totaux exacts
 * dépenses/acomptes, agrégat par travailleur et par jour, mois isolé.
 */
class DailyBookSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['daily_book.pin' => '2468']);
        $this->postJson('/carnet/api/pin', ['pin' => '2468'])->assertOk();
    }

    public function test_month_summary_totals_by_worker_and_day(): void
    {
        DailyBookEntry::create(['type' => 'expense', 'label' => 'Légumes', 'amount' => 86.40, 'entry_date' => '2026-07-03', 'branch_id' => 1]);
        DailyBookEntry::create(['type' => 'expense', 'label' => 'Pain', 'amount' => 13.60, 'entry_date' => '2026-07-03', 'branch_id' => 1]);
        DailyBookEntry::create(['type' => 'advance', 'label' => 'Acompte', 'worker_name' => 'Karim', 'amount' => 50, 'entry_date' => '2026-07-05', 'branch_id' => 1]);
        DailyBookEntry::create(['type' => 'advance', 'label' => 'Acompte', 'worker_name' => 'Karim', 'amount' => 30, 'entry_date' => '2026-07-12', 'branch_id' => 1]);
        DailyBookEntry::create(['type' => 'advance', 'label' => 'Acompte', 'worker_name' => 'Sofia', 'amount' => 20, 'entry_date' => '2026-07-12', 'branch_id' => 1]);
        DailyBookEntry::create(['type' => 'note', 'label' => 'Mémo', 'entry_date' => '2026-07-12', 'branch_id' => 1]);
        // Hors mois — ne doit PAS compter.
        DailyBookEntry::create(['type' => 'expense', 'label' => 'Juin', 'amount' => 999, 'entry_date' => '2026-06-30', 'branch_id' => 1]);

        $res = $this->getJson('/carnet/api/summary/month?month=2026-07')->assertOk()->json('data');

        $this->assertEqualsWithDelta(100.0, $res['total_expenses'], 0.001);
        $this->assertEqualsWithDelta(100.0, $res['total_advances'], 0.001);
        $this->assertEqualsWithDelta(200.0, $res['total_out'], 0.001);
        $this->assertSame(1, $res['notes_count']);

        $byWorker = collect($res['by_worker'])->keyBy('worker_name');
        $this->assertEqualsWithDelta(80.0, $byWorker['Karim']['total'], 0.001);
        $this->assertSame(2, $byWorker['Karim']['count']);
        $this->assertEqualsWithDelta(20.0, $byWorker['Sofia']['total'], 0.001);

        $byDay = collect($res['by_day'])->keyBy('date');
        $this->assertEqualsWithDelta(100.0, $byDay['2026-07-03']['total'], 0.001);
        $this->assertEqualsWithDelta(50.0, $byDay['2026-07-12']['total'], 0.001);
    }

    public function test_month_param_is_required_and_validated(): void
    {
        $this->getJson('/carnet/api/summary/month')->assertStatus(422);
        $this->getJson('/carnet/api/summary/month?month=2026-13')->assertStatus(422);
    }
}
