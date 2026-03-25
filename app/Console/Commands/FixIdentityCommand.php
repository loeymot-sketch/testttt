<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;

class FixIdentityCommand extends Command
{
    protected $signature = 'settings:fix-identity {--dry-run : Show changes without applying}';
    protected $description = 'Update company, branch, and user data from Bangladesh demo to Le Cayenne / France';

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        $this->info($dry ? '=== DRY RUN ===' : '=== Applying identity fix ===');

        // 1. Company settings
        $this->info('1. Company settings → Le Cayenne, France');
        if (!$dry) {
            Settings::group('company')->set([
                'company_name'         => 'Le Cayenne',
                'company_email'        => 'contact@lecayenne.fr',
                'company_phone'        => '+33600000000',
                'company_website'      => 'https://lecayenne.fr',
                'company_city'         => 'Paris',
                'company_state'        => 'Île-de-France',
                'company_country_code' => 'FRA',
                'company_zip_code'     => '75000',
                'company_address'      => 'Paris, France',
            ]);
        }

        // 2. Main branch
        $this->info('2. Main branch → Le Cayenne (principal), Paris');
        $branch = DB::table('branches')->first();
        if ($branch && !$dry) {
            DB::table('branches')->where('id', $branch->id)->update([
                'name'      => 'Le Cayenne (principal)',
                'email'     => 'contact@lecayenne.fr',
                'phone'     => '+33600000000',
                'city'      => 'Paris',
                'state'     => 'Île-de-France',
                'zip_code'  => '75000',
                'address'   => 'Paris, France',
                'latitude'  => 48.8566,
                'longitude' => 2.3522,
            ]);
        }

        // 3. Fix admin user country code
        $this->info('3. Users → country_code +33');
        if (!$dry) {
            DB::table('users')
                ->where('country_code', '+880')
                ->update(['country_code' => '+33']);
        }
        $affected = DB::table('users')->where('country_code', '+880')->count();
        $this->info("   Users with +880: {$affected}");

        // 4. Fix addresses from Dhaka
        $this->info('4. Addresses → Paris, France');
        if (!$dry) {
            DB::table('addresses')
                ->where('address', 'like', '%Dhaka%')
                ->orWhere('address', 'like', '%Bangladesh%')
                ->update([
                    'address'   => 'Paris, France',
                    'latitude'  => '48.8566',
                    'longitude' => '2.3522',
                ]);
        }
        $addrCount = DB::table('addresses')
            ->where('address', 'like', '%Dhaka%')
            ->orWhere('address', 'like', '%Bangladesh%')
            ->count();
        $this->info("   Addresses with Dhaka/Bangladesh: {$addrCount}");

        if ($dry) {
            $this->warn('No changes applied (dry-run). Remove --dry-run to apply.');
        } else {
            $this->info('Identity fix applied successfully.');
        }

        $this->newLine();
        $this->comment('Si le login admin@lecayenne.fr échoue encore : php artisan foodking:ensure-admin');

        return 0;
    }
}
