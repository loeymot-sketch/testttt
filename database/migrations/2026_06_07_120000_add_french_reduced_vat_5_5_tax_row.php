<?php

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Tax;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * [GOAL-100% G7 / HEAL-VAT55 2026-06-07] ADD the French reduced VAT rate 5,5 %.
 *
 * LEGAL FACT (not a business decision):
 *   The French reduced VAT rate ("taux réduit") for applicable food/drink — sealed
 *   bottles/cans, bottled water, conservable/packaged cold items — is **5,5 %**
 *   (CGI art. 278-0 bis). Hot/immediate-consumption items stay at the intermediate
 *   10 % rate (CGI art. 279); standard is 20 % (CGI art. 278).
 *
 * THE DEFECT THIS FIXES:
 *   The seeded `taxes` table (TaxTableSeeder, Bangladeshi GST template legacy) has:
 *     id1 No-VAT 0%  | id2 "VAT" VAT-5%  5,0%  | id3 "VAT" VAT-10% 10%
 *     id4 GST-5% (unused) | id5 GST-10% (unused)
 *   ⚠️ id2 is **5,0 %**, which is NOT a legal French food/drink rate. The legal
 *   reduced rate is **5,5 %**, and there is currently NO 5,5 % row. Binding any
 *   conservable/bottled product to id2 (5,0 %) would UNDER-COLLECT vs the legal
 *   5,5 % — a fiscal under-declaration for a VAT-registered business (E.DELICE SAS).
 *
 * WHAT THIS MIGRATION DOES (ADDITIVE, low-risk, idempotent):
 *   Inserts ONE correct "VAT 5.5" row (tax_rate = 5.500000, type = PERCENTAGE,
 *   status = ACTIVE), keyed by a NEW code `VAT-5.5%`. It does NOT touch id2, the
 *   45 live items (all on tax_id=3 = VAT-10%), or any other row. It does NOT assign
 *   the new rate to any item — that mapping is the OWNER's call (GOAL §G gate G7:
 *   "do you sell any sealed bottle/can or conservable cold item?"). Until an item is
 *   explicitly re-pointed by the owner, this row is inert (PricingService resolves
 *   tax by `item.tax_id`; nothing points here).
 *
 * id2 (5,0 %) is left UNTOUCHED on purpose — it may be referenced by historical
 * data and deleting/repurposing it could break audit traceability. It is simply
 * legally wrong for France and should be DEPRECATED / ignored (owner + accountant
 * decision) — NOT silently used. The correct reduced rate to map SKUs to is THIS
 * new 5,5 % row.
 *
 * IDEMPOTENCY: `taxes.code` has NO unique DB index (verified — create migration
 * declares `string('code')` with no `->unique()`). So `insertOrIgnore` would NOT
 * dedupe on re-run and would create a DUPLICATE 5,5 % row. Idempotency therefore
 * comes from an existence-check via `Tax::updateOrCreate(['code' => ...])` — the
 * exact pattern TaxTableSeeder already uses. Re-running this migration (or a fresh
 * install that also runs the updated seeder) yields exactly ONE 5,5 % row.
 */
return new class extends Migration
{
    private const REDUCED_VAT_CODE = 'VAT-5.5%';

    public function up(): void
    {
        if (! Schema::hasTable('taxes')) {
            return;
        }

        $tax = Tax::updateOrCreate(
            ['code' => self::REDUCED_VAT_CODE],
            [
                'name'       => 'VAT 5.5',
                'tax_rate'   => 5.5,            // decimal(13,6) → 5.500000
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        Log::channel('fiscal')->info('goal-100pct.g7.french-reduced-vat-5_5-row-ensured', [
            'tax_id'    => $tax->id,
            'code'      => $tax->code,
            'tax_rate'  => $tax->tax_rate,
            'wasRecentlyCreated' => $tax->wasRecentlyCreated,
            'note'      => 'French legal reduced rate 5,5% (CGI 278-0 bis). id2=5,0% is legally wrong, left untouched. Item assignment = owner gate G7.',
            'event'     => 'fiscal.tax.reduced_vat_5_5_added',
        ]);
    }

    public function down(): void
    {
        // Reversible, but only remove the row if it is unused (no item points at it),
        // so a rollback can never orphan a live item's tax_id. If any item references
        // the row, leave it in place (the owner deliberately bound a SKU to 5,5%).
        if (! Schema::hasTable('taxes')) {
            return;
        }

        $tax = Tax::where('code', self::REDUCED_VAT_CODE)->first();
        if ($tax === null) {
            return;
        }

        $referenced = \App\Models\Item::withTrashed()->where('tax_id', $tax->id)->exists();
        if ($referenced) {
            Log::channel('fiscal')->warning('goal-100pct.g7.french-reduced-vat-5_5-rollback-skipped', [
                'tax_id' => $tax->id,
                'reason' => 'At least one item references the 5,5% row; refusing to delete to avoid orphaning a live tax_id.',
            ]);

            return;
        }

        $tax->delete();
    }
};
