<?php

namespace Tests\Feature\Fiscal;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Item;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL-GOLIVE-VAT10 2026-05-30] Sentinel — every active menu item must carry
 * the canonical VAT 10% (PERCENTAGE) tax after a fresh seed.
 *
 * Le Cayenne is a French fast-food charging 10% VAT INCLUDED in the price (TTC).
 * The go-live blocker B1 was: the menu seeded at 0% VAT (config default_tax_id=1
 * = "No-VAT"). MenuSeeder::defaultTaxId() now resolves the VAT 10% row by
 * attributes. This sentinel fails CI if any active item ever drifts back to a
 * 0%-rate or NULL tax — preventing a silent regression to fiscally-wrong
 * receipts.
 *
 * SCOPE (honest, per abuse-e2e r2 P3): it proves "every active item the
 * MenuSeeder PRODUCES carries VAT 10% PERCENTAGE". It does NOT prove the
 * canonical 45-item COUNT — the committed config/menu.php items array is stale
 * (old/fictional group slugs), so a bare migrate:fresh --seed yields only ~8
 * items. That gap is covered elsewhere: the canonical menu SSOT is the live DB
 * (CLAUDE.md §3bis), the production cutover PRESERVES it (PROD_CUTOVER_RUNBOOK
 * forbids migrate:fresh --seed for the menu), and app:preflight-production WARNS
 * (MENU_COUNT) if the deployed menu is incomplete (< 40 items).
 */
class MenuVat10PercentSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_active_item_carries_vat_10_percent_after_seed(): void
    {
        // TaxTableSeeder + MenuSeeder are the canonical menu seed path.
        $this->seed(\Database\Seeders\TaxTableSeeder::class);
        $this->seed(\Database\Seeders\MenuSeeder::class);

        $activeItems = Item::query()->where('status', Status::ACTIVE)->get(['id', 'name', 'tax_id']);
        $this->assertGreaterThan(0, $activeItems->count(), 'MenuSeeder must produce active items');

        $taxesById = Tax::query()->get()->keyBy('id');

        $offenders = [];
        foreach ($activeItems as $item) {
            $tax = $item->tax_id ? ($taxesById[$item->tax_id] ?? null) : null;
            $rate = $tax ? (float) $tax->tax_rate : 0.0;
            $type = $tax ? (int) $tax->type : -1;
            if ($rate != 10.0 || $type !== TaxType::PERCENTAGE) {
                $offenders[] = "{$item->name} (tax_id=" . ($item->tax_id ?? 'NULL') . ", rate={$rate})";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "All active items must carry VAT 10% PERCENTAGE. Offenders: " . implode('; ', $offenders)
        );
    }
}
