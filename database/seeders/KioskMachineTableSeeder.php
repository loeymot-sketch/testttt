<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\KioskMachine;
use App\Models\User;
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

        // Owner = admin Le Cayenne (E2E / ensure-admin change souvent l’id utilisateur ; user_id=1 cassait l’auto-login borne).
        $owner = User::query()
            ->where('email', 'admin@lecayenne.fr')
            ->where('status', Status::ACTIVE)
            ->first()
            ?? User::query()->orderBy('id')->first();

        if (! $owner) {
            \Illuminate\Support\Facades\Log::warning('[KioskMachineTableSeeder] No user row; skipping kiosk machines.');

            return;
        }

        $ownerBranch = (int) ($owner->branch_id ?: 1);

        // [GAP-19-3] Borne principale Le Cayenne — updateOrCreate pour réaligner user/branch après re-seed E2E.
        KioskMachine::updateOrCreate(
            ['username' => 'kiosk-lecayenne'],
            [
                'user_id' => $owner->id,
                'branch_id' => $ownerBranch,
                'machine_id' => 'KIOSK-LC-001',
                'password' => bcrypt('kiosk123'),
                'status' => Status::ACTIVE,
            ]
        );

        if ($envService->getValue('DEMO')) {
            KioskMachine::updateOrCreate(
                ['username' => 'mirpur1'],
                [
                    'user_id' => $owner->id,
                    'branch_id' => $ownerBranch,
                    'machine_id' => '12345',
                    'password' => bcrypt('123456'),
                    'status' => Status::ACTIVE,
                ]
            );
            KioskMachine::updateOrCreate(
                ['username' => 'gulshan1'],
                [
                    'user_id' => $owner->id,
                    'branch_id' => 2,
                    'machine_id' => '67891',
                    'password' => bcrypt('123456'),
                    'status' => Status::ACTIVE,
                ]
            );
        }
    }
}
