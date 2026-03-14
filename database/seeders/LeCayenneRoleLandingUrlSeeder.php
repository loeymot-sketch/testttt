<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder : Configuration landing_url pour rôles LeCayenne
 * POS Operator → pos
 * Chef → kitchen-display-system
 * Administrator → dashboard
 */
class LeCayenneRoleLandingUrlSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        // Mapping rôle → landing_url
        $roleLandingUrls = [
            'POS Operator'  => 'pos',
            'Chef'            => 'kitchen-display-system',
            'Administrator'   => 'dashboard',
            'Admin'           => 'dashboard',
            'Branch Manager'  => 'dashboard',
        ];

        foreach ($roleLandingUrls as $roleName => $landingUrl) {
            $updated = DB::table('roles')
                ->where('name', $roleName)
                ->update(['landing_url' => $landingUrl, 'updated_at' => now()]);

            if ($updated) {
                $this->command->info("✓ Role '{$roleName}' → landing_url = '{$landingUrl}'");
            } else {
                $this->command->warn("⚠ Role '{$roleName}' non trouvé");
            }
        }

        // Vérification finale
        $count = DB::table('roles')->whereNotNull('landing_url')->count();
        $this->command->info("Total: {$count} rôles configurés avec landing_url");
    }
}
