<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\PermissionController;
use App\Services\PermissionService;
use Tests\TestCase;

/**
 * [GOAL-CMS-2026-05-18 M-R3-P0-A sentinel — Round 3 T-2.2.1 Arch F1]
 *
 * Permission enumeration via unauthenticated `index`: Wave 5H hardening gated
 * Role/Branch/Administrator FormRequests but FORGOT Permission. The
 * `PermissionController::__construct()` previously declared
 * `$this->middleware(['permission:settings'])->only('update')` which left
 * `index` and `show` accessible to any auth:sanctum holder (POS Operator,
 * Chef, stale Customer token). Such a caller could enumerate the full
 * permission matrix of any role via `GET /admin/setting/permission/{role}`.
 *
 * Heal: drop `->only('update')` so the `permission:settings` gate applies to
 * every controller method (index + update). Sentinel asserts the controller's
 * middleware list does NOT scope the `permission:settings` guard to a
 * subset of methods (which is what made `index` reachable).
 *
 * @group permission
 * @group sentinel
 */
class PermissionControllerIndexAuthzTest extends TestCase
{
    public function test_permission_controller_gates_every_method_with_settings_middleware(): void
    {
        $controller = new PermissionController($this->app->make(PermissionService::class));

        $middleware = $controller->getMiddleware();

        $found = false;
        foreach ($middleware as $entry) {
            $isPermissionSettings = $entry['middleware'] === 'permission:settings'
                || (is_string($entry['middleware'] ?? null) && str_contains($entry['middleware'], 'permission:settings'))
                || (is_array($entry['middleware'] ?? null) && in_array('permission:settings', $entry['middleware'], true));
            if (! $isPermissionSettings) {
                continue;
            }
            $found = true;

            // Critical assertion: the gate must NOT be `->only('update')` (or
            // any subset that excludes `index`). The R3 heal removed `->only()`
            // so `options['only']` should be empty / absent.
            $only = $entry['options']['only'] ?? null;
            $this->assertTrue(
                empty($only) || in_array('index', (array) $only, true),
                'PermissionController.middleware(permission:settings) must gate `index` — heal C-P0-H + M-R3-P0-A. '
                . 'Currently only=[' . implode(',', (array) $only) . '].'
            );
        }

        $this->assertTrue(
            $found,
            'PermissionController must declare middleware `permission:settings` (was removed by R3 T-2.2.1 Arch F1 regression).'
        );
    }
}
