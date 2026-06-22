<?php

/**
 * [GENIE B8 2026-06-16] SettingService::list() (/frontend/setting, every client boot) now caches the merged
 * payload app-level with event-driven invalidation + a TTL floor. Proves: cache-hit issues 0 queries, and a
 * SettingsUpdated event invalidates so the next read reflects the new value (no stale config — the gap that
 * made enabling the package's own global cache unsafe).
 */

namespace Tests\Feature\Settings;

use App\Events\SettingsUpdated;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class FrontendSettingsCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        Cache::forget(SettingService::CACHE_KEY);
    }

    /** @test */
    public function test_second_call_is_a_cache_hit_with_zero_queries(): void
    {
        $svc = app(SettingService::class);
        $svc->list(); // miss → populates cache

        DB::enableQueryLog();
        $svc->list(); // hit → must not touch the DB
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, 'cached settings payload must not re-query the DB');
    }

    /** @test */
    public function test_settings_updated_event_invalidates_so_next_read_is_fresh(): void
    {
        $svc = app(SettingService::class);
        $svc->list(); // warm cache
        $this->assertNotNull(Cache::get(SettingService::CACHE_KEY), 'precondition: cache warm');

        Settings::group('company')->set(['company_name' => 'GENIE-NEW-NAME']);
        event(new SettingsUpdated(['company'])); // listener forgets the cache

        $this->assertNull(Cache::get(SettingService::CACHE_KEY), 'event must invalidate the cache');
        $fresh = $svc->list();
        $this->assertSame('GENIE-NEW-NAME', $fresh['company_name'] ?? null, 'next read must reflect the saved value (no stale)');
    }
}
