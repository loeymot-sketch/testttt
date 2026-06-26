<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\LicenseController;
use App\Services\LicenseService;
use Tests\TestCase;

/**
 * [GOAL test-e2e all-systems 2026-06-26 — SET-03 sentinel] License key read leak.
 *
 * The W5 CENTRAL adversarial audit found LicenseController declared
 * `$this->middleware(['permission:settings'])->only('update')`, leaving `index`
 * (GET /api/admin/setting/license) reachable by ANY auth:sanctum staff token
 * (POS Operator / Branch Manager / Chef — none holds `settings`). `LicenseResource`
 * returns `license_key` unmasked, and license_key == MIX_API_KEY (config/app.php
 * api_key) — the x-api-key validated on the whole admin group.
 *
 * This is the un-corrected twin of SET-01 (PaymentGateway/Sms) + SET-02 (Mail),
 * which the GAP-19-2 pass healed to `->only('index','update')` but missed here.
 *
 * Severity P2 (not P1): the leaked value is already injected into every admin SPA
 * session (window.foodkingConfig.apiKey) so a real operator who reaches this
 * endpoint already holds it — no NEW capability. But the read-gate asymmetry is a
 * real doctrine inconsistency and must be ratcheted shut.
 *
 * Heal: `->only('index', 'update')`. Gating index breaks no non-settings surface
 * (the Settings component is the sole consumer). Sentinel asserts the
 * `permission:settings` guard is NOT scoped to a method subset that excludes index.
 *
 * @group sentinel
 * @group security
 */
class LicenseKeyReadAuthzSentinelTest extends TestCase
{
    public function test_license_controller_gates_index_with_settings(): void
    {
        $controller = new LicenseController($this->app->make(LicenseService::class));
        $middleware = $controller->getMiddleware();
        $found = false;

        foreach ($middleware as $entry) {
            $mw = $entry['middleware'] ?? null;
            $isPermissionSettings = $mw === 'permission:settings'
                || (is_string($mw) && str_contains($mw, 'permission:settings'))
                || (is_array($mw) && in_array('permission:settings', $mw, true));
            if (! $isPermissionSettings) {
                continue;
            }
            $found = true;

            $only = $entry['options']['only'] ?? null;
            $this->assertTrue(
                empty($only) || in_array('index', (array) $only, true),
                'LicenseController.middleware(permission:settings) must gate `index` '
                . '(SET-03: license_key == MIX_API_KEY leak to non-settings staff). '
                . 'Currently only=[' . implode(',', (array) $only) . '].'
            );
        }

        $this->assertTrue(
            $found,
            'LicenseController must declare middleware `permission:settings` gating index + update.'
        );
    }
}
