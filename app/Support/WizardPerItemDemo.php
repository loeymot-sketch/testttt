<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Single gate for "wizard per item" Demo V2 (composer item-owned) visibility.
 *
 * Production: controlled by `FEATURE_WIZARD_PER_ITEM_DEMO` / config only.
 * Local E2E: Playwright can send {@see self::PLAYWRIGHT_HEADER} on **every** request
 * (document + XHR) so Blade + middleware + router payload stay aligned without toggling
 * `.env` on each developer machine. Requires `APP_ENV=local` only (not production / staging).
 */
final class WizardPerItemDemo
{
    public const PLAYWRIGHT_HEADER = 'X-Foodking-Playwright-E2e';

    public static function playwrightLocalBypass(?Request $request): bool
    {
        if ($request === null) {
            return false;
        }

        if ($request->header(self::PLAYWRIGHT_HEADER) !== '1') {
            return false;
        }

        // Opt-in E2E (Playwright) when APP_ENV n’est pas `local` (ex. machine dev en staging).
        if (config('features.e2e_wizard_header')) {
            return true;
        }

        // Par défaut : uniquement local + header Playwright (évite prod/staging ouverts par erreur).
        return app()->environment('local');
    }

    public static function enabled(?Request $request = null): bool
    {
        if ((bool) config('catalog_v15.features.wizard_per_item_demo.enabled', false)) {
            return true;
        }

        return self::playwrightLocalBypass($request);
    }
}
