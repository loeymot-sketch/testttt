<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\KioskSetupRequest;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;

class KioskSetupService
{
    /** Settings group this service owns. */
    private const GROUP = 'kiosk_setup';

    /**
     * @throws Exception
     */
    public function list(): array
    {
        try {
            return Settings::group(self::GROUP)->all();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(KioskSetupRequest $request): array
    {
        try {
            Settings::group(self::GROUP)->set($request->validated());

            // [abuse-heal 2026-06-18] Smartisan keys its cache on "keys=<list>&group=<group>".
            // ->set($assoc) only forgets keys=<the-assoc-keys>, but list() reads the group ->all()
            // entry keyed keys=<EMPTY>. Those entries DIFFER, so without this the group blob stayed
            // stale (admins saw the OLD kiosk-setup until cache aged out). Forget the all() key
            // explicitly, using the package's own resolver so the key matches exactly.
            $this->forgetGroupAllCache();

            return $this->list();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [abuse-heal 2026-06-18] Forget the group's ->all() cache entry (keys=<empty>) that
     * Settings::set() leaves stale. No-op when settings caching is disabled.
     */
    private function forgetGroupAllCache(): void
    {
        if (! config('settings.cache.enabled')) {
            return;
        }

        // resolveCacheKey(null) on a group()-scoped instance yields the exact key list() caches.
        $cacheKey = Settings::group(self::GROUP)->resolveCacheKey(null);
        Cache::store(config('settings.cache.store'))->forget($cacheKey);
    }
}
