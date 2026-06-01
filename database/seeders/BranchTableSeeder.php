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
        // [GOAL_SECOND_DEGREE_INDIRECT 2026-06-01 — DEL-ORIGIN-01 + DEL-FEE-01]
        // Real restaurant: 437 Rue Élie Gruyelle, 62110 Hénin-Beaumont
        // (geocoded rooftop lat 50.4215667 / lng 2.9549060). The previous Paris
        // coords made every delivery distance compute from the wrong city.
        // Delivery fee config encodes the owner rule "5€ ≤5km, +1€/km beyond"
        // ≡ max(minimum, base + per_km*d) with base=0 / per_km=1 / minimum=5.
        Branch::create([
            'name'      => 'Le Cayenne (principal)',
            'email'     => 'contact@lecayenne.fr',
            'phone'     => '+33600000000',
            'latitude'  => 50.4215667,
            'longitude' => 2.9549060,
            'zone'      => json_encode('[{"lat":50.45,"lng":2.92},{"lat":50.45,"lng":2.99},{"lat":50.39,"lng":2.99},{"lat":50.39,"lng":2.92}]'),
            'city'      => 'Hénin-Beaumont',
            'state'     => 'Hauts-de-France',
            'zip_code'  => '62110',
            'address'   => '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont',
            'delivery_fee_base'    => 0,
            'delivery_fee_per_km'  => 1,
            'delivery_fee_minimum' => 5,
            'status'    => Status::ACTIVE,
        ]);

        $envService = new EnvEditor();
        if ($envService->getValue('DEMO')) {
            Branch::create([
                'name'      => 'Le Cayenne (démo)',
                'email'     => 'demo@lecayenne.fr',
                'phone'     => '+33600000001',
                'latitude'  => 50.4250000,
                'longitude' => 2.9450000,
                'zone'      => json_encode('[{"lat":50.45,"lng":2.92},{"lat":50.45,"lng":2.98},{"lat":50.39,"lng":2.98},{"lat":50.39,"lng":2.92}]'),
                'city'      => 'Hénin-Beaumont',
                'state'     => 'Hauts-de-France',
                'zip_code'  => '62110',
                'address'   => 'Hénin-Beaumont (démo)',
                'delivery_fee_base'    => 0,
                'delivery_fee_per_km'  => 1,
                'delivery_fee_minimum' => 5,
                'status'    => Status::ACTIVE,
            ]);
        }
    }
}