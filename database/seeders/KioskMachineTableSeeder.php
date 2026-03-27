<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\KioskMachine;
use Illuminate\Database\Seeder;
use Dipokhalder\EnvEditor\EnvEditor;

class KioskMachineTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // [AUDIT-P0-C] Safety guard: never seed default kiosk credentials in production.
        if (app()->environment('production')) {
            \Illuminate\Support\Facades\Log::warning('[KioskMachineTableSeeder] Blocked in production environment.');
            return;
        }

        $envService = new EnvEditor();

        // [GAP-19-3] Always create the primary kiosk machine for branch 1 (Le Cayenne).
        // Without this, kiosk login is impossible in production (non-DEMO) environments.
        // The admin user (id=1) owns the kiosk machine; branch_id=1 is the main branch.
        KioskMachine::firstOrCreate(
            ['username' => 'kiosk-lecayenne'],
            [
                'user_id'    => 1,
                'branch_id'  => 1,
                'machine_id' => 'KIOSK-LC-001',
                'username'   => 'kiosk-lecayenne',
                'password'   => bcrypt('kiosk123'),
                'status'     => Status::ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        if ($envService->getValue('DEMO')) {
            KioskMachine::firstOrCreate(
                ['username' => 'mirpur1'],
                [
                    'user_id'    => 1,
                    'branch_id'  => 1,
                    'machine_id' => '12345',
                    'username'   => 'mirpur1',
                    'password'   => bcrypt('123456'),
                    'status'     => Status::ACTIVE,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            KioskMachine::firstOrCreate(
                ['username' => 'gulshan1'],
                [
                    'user_id'    => 1,
                    'branch_id'  => 2,
                    'machine_id' => '67891',
                    'username'   => 'gulshan1',
                    'password'   => bcrypt('123456'),
                    'status'     => Status::ACTIVE,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
