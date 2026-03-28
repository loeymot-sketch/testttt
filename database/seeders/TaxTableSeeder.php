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
