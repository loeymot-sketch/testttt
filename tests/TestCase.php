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
        if (!Schema::hasTable('settings')) {
            return;
        }

        if (DB::table('settings')->count() > 0) {
            return;
        }

        DB::table('settings')->insert([
            ['type' => 'site_title', 'value' => 'FoodKing Test', 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'favicon_logo', 'value' => null, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'site_logo', 'value' => null, 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'currency', 'value' => 'EUR', 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'currency_symbol', 'value' => '€', 'created_at' => now(), 'updated_at' => now()],
            ['type' => 'order_prefix', 'value' => 'FK', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
