<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Item;
use App\Models\Tax;
use Illuminate\Console\Command;

/**
 * [GOAL-GOLIVE-VAT10 2026-05-30] Assign the canonical VAT 10% (TTC) tax to the
 * live menu, closing supervisor blocker B1 WITHOUT a migrate:fresh.
 *
 * Le Cayenne is a French fast-food charging 10% VAT INCLUDED in the displayed
 * price. PricingService runs in tax-inclusive mode (pricing.tax_inclusive_prices
 * =true) so this binding EXTRACTS 0,45 € from a 5,00 € line — displayed prices
 * do NOT change. We bind by RESOLVING the VAT 10% row by attributes (rate=10 +
 * PERCENTAGE + name='VAT'), never a hardcoded id (id=3 VAT vs id=5 GST are both
 * 10%; AUTO_INCREMENT is non-deterministic). Idempotent: only re-points items
 * that are NULL or on a 0%-rate tax, so re-runs are no-ops.
 *
 * Use --dry-run to preview. This touches DATA only (orders/Z chain untouched);
 * historical orders keep their frozen composition_snapshot + signed Z.
 */
class AssignMenuVatCommand extends Command
{
    protected $signature = 'fiscal:assign-menu-vat {--dry-run : Preview without writing}';

    protected $description = 'Bind all active menu items to the canonical VAT 10% (TTC) tax (go-live blocker B1)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $vat10Id = Tax::query()
            ->where('tax_rate', 10)
            ->where('type', TaxType::PERCENTAGE)
            ->where('name', 'VAT')
            ->orderBy('id')
            ->value('id')
            ?? Tax::query()
                ->where('tax_rate', 10)
                ->where('type', TaxType::PERCENTAGE)
                ->orderBy('id')
                ->value('id');

        if (! $vat10Id) {
            $this->error('No VAT 10% PERCENTAGE tax row found. Seed TaxTableSeeder first.');
            return self::FAILURE;
        }

        // Zero-rate tax ids (NULL tax_id OR a tax whose rate is 0) need fixing.
        $zeroRateTaxIds = Tax::query()->where('tax_rate', 0)->pluck('id')->all();

        $target = Item::query()
            ->where('status', Status::ACTIVE)
            ->where(function ($q) use ($zeroRateTaxIds) {
                $q->whereNull('tax_id');
                if (! empty($zeroRateTaxIds)) {
                    $q->orWhereIn('tax_id', $zeroRateTaxIds);
                }
            });

        $count = (clone $target)->count();
        $already = Item::query()->where('status', Status::ACTIVE)->where('tax_id', $vat10Id)->count();

        $this->info("VAT 10% tax id resolved = {$vat10Id}");
        $this->info("Active items already on VAT 10% : {$already}");
        $this->info(($dryRun ? '[DRY-RUN] would re-point' : 're-pointing') . " {$count} item(s) (NULL or 0%-rate) → VAT 10%");

        if ($dryRun) {
            return self::SUCCESS;
        }

        if ($count > 0) {
            (clone $target)->update(['tax_id' => $vat10Id]);
        }

        $final = Item::query()->where('status', Status::ACTIVE)->where('tax_id', $vat10Id)->count();
        $totalActive = Item::query()->where('status', Status::ACTIVE)->count();
        $this->info("Done. Active items on VAT 10% : {$final} / {$totalActive} active.");

        if ($final !== $totalActive) {
            $this->warn("Some active items are NOT on VAT 10% (they were on a non-zero, non-VAT tax). Review manually.");
        }

        return self::SUCCESS;
    }
}
