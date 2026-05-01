<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Ask;
use App\Enums\DisplayMode;
use App\Enums\Status;
use App\Console\Commands\CleanupDemoDataCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FrenchRuntimeNoBangladeshDemoDataSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_demo_data_command_reports_legacy_rows_without_mutating_database(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Legacy User',
            'email' => 'legacy@example.com',
            'username' => 'legacy-user',
            'password' => bcrypt('secret'),
            'country_code' => '+880',
            'is_guest' => Ask::NO,
            'status' => Status::ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('branches')->insert([
            'name' => 'Turcotte, Pagac And Sauer Branch',
            'email' => 'legacy-branch@example.com',
            'phone' => '+8801257654433',
            'latitude' => '23.7948',
            'longitude' => '90.4143',
            'city' => 'Dhaka',
            'state' => 'Dhaka',
            'zip_code' => '1200',
            'address' => 'Dhaka Bangladesh',
            'status' => Status::ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('addresses')->insert([
            'user_id' => $userId,
            'label' => 'Home',
            'address' => 'Dhaka Bangladesh',
            'latitude' => '23.7948',
            'longitude' => '90.4143',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('currencies')->insert([
            'name' => 'Taka',
            'symbol' => 'BDT',
            'code' => 'BDT',
            'is_cryptocurrency' => Ask::NO,
            'exchange_rate' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('languages')->insert([
            'name' => 'Bangla',
            'code' => 'bn',
            'display_mode' => DisplayMode::LTR,
            'status' => Status::ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('foodking:cleanup-demo-data --dry-run --json')->assertExitCode(0);
        $payload = app(CleanupDemoDataCommand::class)->buildReport();

        $this->assertFalse($payload['mutates_database']);
        $this->assertSame('fr', $payload['policy']['primary_locale']);
        $this->assertSame('EUR', $payload['policy']['primary_currency']);
        $this->assertSame(1, $payload['counts']['branches_with_faker_or_legacy_name']);
        $this->assertSame(1, $payload['counts']['users_with_bangladesh_country_code']);
        $this->assertSame(1, $payload['counts']['addresses_with_dhaka_or_bangladesh']);
        $this->assertSame(1, $payload['counts']['currencies_bdt_inr_ngn_ars']);
        $this->assertSame(1, $payload['counts']['languages_not_fr_en_ar']);

        $this->assertDatabaseHas('users', ['id' => $userId, 'country_code' => '+880']);
        $this->assertDatabaseHas('addresses', ['user_id' => $userId, 'address' => 'Dhaka Bangladesh']);
    }

    public function test_runtime_seeders_do_not_reintroduce_bangladesh_currency_or_bundle_bangla_locale(): void
    {
        $currencySeeder = file_get_contents(base_path('database/seeders/CurrencyTableSeeder.php'));
        $i18nRuntime = file_get_contents(base_path('resources/js/i18n.js'));

        $this->assertStringContainsString("'code' => 'EUR'", $currencySeeder);
        $this->assertStringNotContainsString("'code' => 'BDT'", $currencySeeder);
        $this->assertStringNotContainsString("languages/bn.json", $i18nRuntime);
        $this->assertStringNotContainsString("languages/de.json", $i18nRuntime);
        $this->assertStringContainsString("const messages = { fr, en, ar }", $i18nRuntime);
    }
}
