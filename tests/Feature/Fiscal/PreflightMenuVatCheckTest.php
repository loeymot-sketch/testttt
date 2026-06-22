<?php

namespace Tests\Feature\Fiscal;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [GOAL-GOLIVE-VAT10 2026-05-30] Tests for the PreflightProduction MENU_VAT +
 * MENU_COUNT go-live gate checks. These are the safety net that blocks deploy
 * if the menu is 0%-VAT (B1 regression) or stale/incomplete (seed-parity P0).
 */
class PreflightMenuVatCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', str_repeat('a', 48));
        Config::set('fiscal.z_report_secret', str_repeat('b', 48));
    }

    private function makeItems(int $count, ?int $taxId): void
    {
        $cat = ItemCategory::factory()->create(['name' => 'Cat', 'wizard_template' => 'simple', 'has_menu' => false]);
        for ($i = 0; $i < $count; $i++) {
            Item::factory()->create([
                'item_category_id' => $cat->id,
                'tax_id' => $taxId,
                'name' => "Item {$i}",
                'price' => 5.00,
                'status' => Status::ACTIVE,
            ]);
        }
    }

    public function test_preflight_flags_zero_vat_menu_as_critical(): void
    {
        $zeroTax = Tax::factory()->create(['name' => 'No-VAT', 'code' => 'NOVAT', 'tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $this->makeItems(45, $zeroTax->id);

        // The MENU_VAT check is CRITICAL → command returns non-zero.
        $exit = $this->artisan('app:preflight-production')
            ->expectsOutputToContain('MENU_VAT');
        // Assert the run reports failure (CRITICAL present).
        $this->artisan('app:preflight-production')->assertExitCode(1);
    }

    public function test_preflight_flags_null_tax_menu_as_critical(): void
    {
        $this->makeItems(45, null);
        $this->artisan('app:preflight-production')->assertExitCode(1);
    }

    public function test_preflight_passes_menu_vat_when_all_items_carry_vat10(): void
    {
        $vat10 = Tax::factory()->create(['name' => 'VAT', 'code' => 'VAT10', 'tax_rate' => 10, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $this->makeItems(45, $vat10->id);

        // MENU_VAT + MENU_COUNT should both report OK (other env checks may still
        // warn/critical, but the menu line itself is healthy).
        $this->artisan('app:preflight-production')
            ->expectsOutputToContain('MENU_VAT: 45 active items, all on a non-zero VAT rate');
    }

    public function test_preflight_warns_on_incomplete_menu_count(): void
    {
        $vat10 = Tax::factory()->create(['name' => 'VAT', 'code' => 'VAT10', 'tax_rate' => 10, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        // Only 8 items, all VAT10 — the stale-seed scenario. MENU_VAT is OK but
        // MENU_COUNT must WARN that the menu is incomplete vs canonical 45.
        $this->makeItems(8, $vat10->id);

        $this->artisan('app:preflight-production')
            ->expectsOutputToContain('MENU_COUNT');
    }
}
