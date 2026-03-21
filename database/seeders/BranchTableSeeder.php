<?php

namespace Database\Seeders;

use Dipokhalder\EnvEditor\EnvEditor;
use App\Enums\Status;
use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Branch::create([
            'name'      => 'Le Cayenne (principal)',
            'email'     => 'contact@lecayenne.fr',
            'phone'     => '+33600000000',
            'latitude'  => 48.8566,
            'longitude' => 2.3522,
            'zone'      => json_encode('[{"lat":48.86,"lng":2.33},{"lat":48.87,"lng":2.36},{"lat":48.85,"lng":2.37},{"lat":48.84,"lng":2.34}]'),
            'city'      => 'Paris',
            'state'     => 'Île-de-France',
            'zip_code'  => '75000',
            'address'   => 'Paris, France',
            'status'    => Status::ACTIVE,
        ]);

        $envService = new EnvEditor();
        if ($envService->getValue('DEMO')) {
            Branch::create([
                'name'      => 'Le Cayenne (démo)',
                'email'     => 'demo@lecayenne.fr',
                'phone'     => '+33600000001',
                'latitude'  => 48.8606,
                'longitude' => 2.3376,
                'zone'      => json_encode('[{"lat":48.86,"lng":2.33},{"lat":48.87,"lng":2.35},{"lat":48.85,"lng":2.36},{"lat":48.84,"lng":2.33}]'),
                'city'      => 'Paris',
                'state'     => 'Île-de-France',
                'zip_code'  => '75001',
                'address'   => 'Paris, France',
                'status'    => Status::ACTIVE,
            ]);
        }
    }
}