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
 * receipts. Runs MenuSeeder so it validates the SEED path (what migrate:fresh
 * --seed produces on the production box), not just the live DB.
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
