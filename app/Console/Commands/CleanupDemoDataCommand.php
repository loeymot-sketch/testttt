<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDemoDataCommand extends Command
{
    protected $signature = 'foodking:cleanup-demo-data {--dry-run : Report legacy/demo data without applying changes} {--json : Print machine-readable JSON}';

    protected $description = 'Audit legacy demo/Bangladesh data before any destructive cleanup.';

    public function handle(): int
    {
        $report = $this->buildReport();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $this->info('FoodKing demo/legacy cleanup audit');
        $this->line('Mode: dry-run only. No row is modified by this command.');
        $this->newLine();

        foreach ($report['counts'] as $key => $count) {
            $this->line(str_pad($key, 42) . ': ' . $count);
        }

        $this->newLine();
        $this->line('Recommendation: fix seed/runtime first, then run a gated data migration if historical rows must be changed.');

        return 0;
    }

    public function buildReport(): array
    {
        return [
            'mode' => 'dry-run',
            'mutates_database' => false,
            'counts' => [
                'branches_with_faker_or_legacy_name' => $this->countBranchesWithLegacyNames(),
                'users_with_bangladesh_country_code' => $this->countLike('users', 'country_code', '+880'),
                'addresses_with_dhaka_or_bangladesh' => $this->countAddressesWithLegacyText('addresses', 'address'),
                'order_addresses_with_dhaka_or_bangladesh' => $this->countAddressesWithLegacyText('order_addresses', 'address'),
                'currencies_not_eur' => $this->countNonEurCurrencies(),
                'currencies_bdt_inr_ngn_ars' => $this->countLegacyCurrencyCodes(),
                'languages_not_fr_en_ar' => $this->countLegacyLanguageCodes(),
            ],
            'policy' => [
                'primary_locale' => 'fr',
                'fallback_locales' => ['en', 'ar'],
                'primary_currency' => 'EUR',
                'destructive_cleanup_requires_gate' => true,
            ],
        ];
    }

    private function countBranchesWithLegacyNames(): int
    {
        if (! $this->tableExists('branches')) {
            return 0;
        }

        return (int) DB::table('branches')
            ->where(function ($query): void {
                $query
                    ->where('name', 'like', '%Turcotte%')
                    ->orWhere('name', 'like', '%Pagac%')
                    ->orWhere('name', 'like', '%Sauer%')
                    ->orWhere('name', 'like', '%Faker%')
                    ->orWhere('address', 'like', '%Dhaka%')
                    ->orWhere('address', 'like', '%Bangladesh%');
            })
            ->count();
    }

    private function countLike(string $table, string $column, string $value): int
    {
        if (! $this->tableExists($table)) {
            return 0;
        }

        return (int) DB::table($table)->where($column, $value)->count();
    }

    private function countAddressesWithLegacyText(string $table, string $column): int
    {
        if (! $this->tableExists($table)) {
            return 0;
        }

        return (int) DB::table($table)
            ->where(function ($query) use ($column): void {
                $query
                    ->where($column, 'like', '%Dhaka%')
                    ->orWhere($column, 'like', '%Bangladesh%');
            })
            ->count();
    }

    private function countNonEurCurrencies(): int
    {
        if (! $this->tableExists('currencies')) {
            return 0;
        }

        return (int) DB::table('currencies')->where('code', '!=', 'EUR')->count();
    }

    private function countLegacyCurrencyCodes(): int
    {
        if (! $this->tableExists('currencies')) {
            return 0;
        }

        return (int) DB::table('currencies')
            ->whereIn('code', ['BDT', 'INR', 'NGN', 'ARS'])
            ->count();
    }

    private function countLegacyLanguageCodes(): int
    {
        if (! $this->tableExists('languages')) {
            return 0;
        }

        return (int) DB::table('languages')
            ->whereNotIn('code', ['fr', 'en', 'ar'])
            ->count();
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }
}
