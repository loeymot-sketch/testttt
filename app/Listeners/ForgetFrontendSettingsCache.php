<?php

namespace App\Listeners;

use App\Events\SettingsUpdated;
use App\Services\SettingService;
use Illuminate\Support\Facades\Cache;

/**
 * [GENIE B8 2026-06-16] Invalidate the cached /frontend/setting payload (SettingService::CACHE_KEY) the
 * moment an admin saves settings, so the change propagates to the frontend immediately rather than waiting
 * out the TTL floor. Covers the groups whose controllers dispatch SettingsUpdated (company/site/order_setup
 * /currency/tax); the other groups self-heal via the 300s TTL.
 */
class ForgetFrontendSettingsCache
{
    public function handle(SettingsUpdated $event): void
    {
        Cache::forget(SettingService::CACHE_KEY);
    }
}
