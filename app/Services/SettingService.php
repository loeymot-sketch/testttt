<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Smartisan\Settings\Facades\Settings;

class SettingService
{
    /** App-level cache key for the merged /frontend/setting payload. */
    public const CACHE_KEY = 'frontend.settings.payload';

    /** TTL floor (seconds) — self-heals groups whose controllers don't dispatch SettingsUpdated. */
    public const CACHE_TTL = 300;

    public function list() : array
    {
        // [GENIE B8 2026-06-16] /frontend/setting issued 10 settings-table SELECTs on every client boot
        // (kiosk idle, web, POS shell). Cache the merged payload app-level (NOT the package's global cache,
        // whose group-all() invalidation is broken). Invalidation: ForgetFrontendSettingsCache forgets this
        // key on the SettingsUpdated event (company/site/order_setup/currency/tax) for instant propagation;
        // the 300s TTL floor self-heals the few groups whose controllers don't dispatch the event. Admin
        // Settings pages read the DB directly (Settings facade), so they always see fresh values.
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, static function (): array {
            $array = [];
            $array = array_merge($array, Settings::group('company')->all());
            $array = array_merge($array, Settings::group('site')->all());
            $array = array_merge($array, Settings::group('theme')->all());
            $array = array_merge($array, Settings::group('otp')->all());
            $array = array_merge($array, Settings::group('social_media')->all());
            $array = array_merge($array, Settings::group('order_setup')->all());
            $array = array_merge($array, Settings::group('notification')->all());
            $array = array_merge($array, Settings::group('cookies')->all());
            // [KIOSK-15] Merge kiosk_setup so SettingResource can expose kiosk fields to the frontend
            $array = array_merge($array, Settings::group('kiosk_setup')->all());
            // [KIOSK-15] Merge loyalty_setup so kiosk can read conversion rates via /frontend/setting
            return array_merge($array, Settings::group('loyalty_setup')->all());
        });
    }
}
