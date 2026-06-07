<?php

namespace Database\Seeders;

use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Enums\TaxType;
use App\Enums\Status;
use App\Models\Tax;

class TaxTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $envService = new EnvEditor();
        $taxes = [
            [
                'name'       => 'No-VAT',
                'code'       => 'VAT-0',
                'tax_rate'   => 0,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
            ],
            [
                'name'       => 'VAT',
                'code'       => 'VAT-5%',
                'tax_rate'   => 5,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
            ],
            [
                'name'       => 'VAT',
                'code'       => 'VAT-10%',
                'tax_rate'   => 10,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
            ],
            [
                'name'       => 'GST',
                'code'       => 'GST-5%',
                'tax_rate'   => 5,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
            ],
            [
                'name'       => 'GST',
                'code'       => 'GST-10%',
                'tax_rate'   => 10,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
            ],
            // [GOAL-100% G7 2026-06-07] French reduced VAT rate "taux réduit" = 5,5 %
            // (CGI art. 278-0 bis) for sealed bottles/cans, bottled water, conservable
            // packaged cold items. The legacy 'VAT-5%' row above (id2, 5,0 %) is NOT a
            // legal French food rate — it is legally wrong and should be deprecated/
            // ignored (owner/accountant call); it is kept only for historical-data
            // referential safety. Bind reduced-rate SKUs to THIS row, never to id2.
            // Item assignment remains the owner's decision (gate G7).
            [
                'name'       => 'VAT 5.5',
                'code'       => 'VAT-5.5%',
                'tax_rate'   => 5.5,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
            ],
        ];

        // Local / production-like setups still need at least one tax row because
        // MenuSeeder references config('menu.settings.default_tax_id') = 1.
        // Previously, taxes were only seeded in DEMO mode, which made a fresh
        // local install crash during MenuSeeder with tax_id foreign-key errors.
        foreach ($taxes as $tax) {
            Tax::updateOrCreate(
                ['code' => $tax['code']],
                $tax + ['updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
