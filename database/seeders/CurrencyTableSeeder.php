<?php

namespace Database\Seeders;


use App\Enums\Ask;
use App\Models\Currency;
use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Database\Seeder;


class CurrencyTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Currency::insert([
            [
                'name' => 'Euro',
                'symbol' => '€',
                'code' => 'EUR',
                'is_cryptocurrency' => Ask::NO,
                'exchange_rate' => 1
            ],
        ]);

        $envService = new EnvEditor();
        if ($envService->getValue('DEMO')) {
            Currency::insert([
                [
                    'name' => 'Dollar US',
                    'symbol' => '$',
                    'code' => 'USD',
                    'is_cryptocurrency' => Ask::NO,
                    'exchange_rate' => 1
                ],
            ]);
        }
    }
}
