<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Set up minimal application settings required by middleware.
     * Called AFTER migrations (RefreshDatabase trait migrates before setUp hooks).
     *
     * Only runs when the `settings` table exists and is empty.
     * This prevents the "Attempt to read property faviconLogo on null" crash
     * that occurs when controllers call Setting::get() with no rows in DB.
     */
    protected function seedMinimalSettings(): void
    {
        $table = config('settings.repositories.database.table', 'settings');

        if (!Schema::hasTable($table)) {
            return;
        }

        if (DB::table($table)->count() > 0) {
            return;
        }

        DB::table($table)->insert([
            ['key' => 'site_title', 'payload' => json_encode('FoodKing Test'), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'favicon_logo', 'payload' => json_encode(null), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_logo', 'payload' => json_encode(null), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'currency', 'payload' => json_encode('EUR'), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'currency_symbol', 'payload' => json_encode('€'), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'order_prefix', 'payload' => json_encode('FK'), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
