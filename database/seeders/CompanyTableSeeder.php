<?php

namespace Database\Seeders;


use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Smartisan\Settings\Facades\Settings;

class CompanyTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Settings::group('company')->set([
            'company_name'         => 'Le Cayenne',
            'company_email'        => 'contact@lecayenne.fr',
            'company_phone'        => '+33600000000',
            'company_website'      => 'https://lecayenne.fr',
            'company_city'         => 'Paris',
            'company_state'        => 'Île-de-France',
            'company_country_code' => 'FRA',
            'company_zip_code'     => '75000',
            'company_address'      => 'Paris, France'
        ]);

        $envService = new EnvEditor();
        $envService->addData([
            'APP_NAME' => "Le Cayenne"
        ]);
        Artisan::call('optimize:clear');
    }
}
