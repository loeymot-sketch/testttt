<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\SalesReportController;
use Tests\TestCase;

/**
 * [GOAL_MGMT_TESTPLAN 2026-06-01] Management ungated-endpoint sentinels.
 *
 * The mgmt-breadth adversarial audit (workflow wf6dhhn09) found endpoints whose
 * permission gate used `->only([...])` but OMITTED a sibling method, leaving it
 * reachable by any auth:sanctum staff:
 *  - REP-AUTHZ-01: SalesReportController gated permission:sales-report only on
 *    index/export/pdf, NOT `overview` → `GET /admin/sales-report/overview`
 *    revenue aggregate readable by non-report staff.
 *  - NC-MSG-CHANGESTATUS-GATE: MessageController gated permission:messages only on
 *    index/show/store/destroy, NOT `changeStatus` (state-mutating) → ungated.
 *
 * Heal: add the omitted method to the existing `->only(...)` list (same pattern
 * as SET-01 gateway heal + PermissionControllerIndexAuthzTest). The legitimate
 * consumer already holds the gating permission, so no surface breaks.
 *
 * @group sentinel
 * @group security
 */
class MgmtReadAuthzGateSentinelTest extends TestCase
{
    public function test_sales_report_overview_is_gated_by_sales_report_permission(): void
    {
        // [abuse-heal 2026-06-20 W5 REP-AUTHZ-OVERVIEW-01] Use the REAL handler method name.
        // The route /overview is handled by salesReportOverview(); the prior 'overview' literal
        // both mis-bound the gate AND made this sentinel false-green (it only string-matched the
        // ->only() array). assertMethodGated now also asserts the method exists on the controller.
        $this->assertMethodGated(
            $this->app->make(SalesReportController::class),
            'permission:sales-report',
            'salesReportOverview',
            'SalesReportController'
        );
    }

    public function test_message_change_status_is_gated_by_messages_permission(): void
    {
        $this->assertMethodGated(
            $this->app->make(MessageController::class),
            'permission:messages',
            'changeStatus',
            'MessageController'
        );
    }

    /** [W5 W5-SET-NOTIF-INDEX-01] FCM push credentials (server key + service-account JSON). */
    public function test_notification_index_is_gated_by_settings_permission(): void
    {
        $this->assertMethodGated(
            $this->app->make(NotificationController::class),
            'permission:settings',
            'index',
            'NotificationController'
        );
    }

    /** [W5 W5-SET-LICENSE-INDEX-02] license_key == MIX_API_KEY == the global x-api-key. */
    public function test_license_index_is_gated_by_settings_permission(): void
    {
        $this->assertMethodGated(
            $this->app->make(LicenseController::class),
            'permission:settings',
            'index',
            'LicenseController'
        );
    }

    private function assertMethodGated(object $controller, string $permission, string $method, string $name): void
    {
        // [abuse-heal 2026-06-20 W5 REP-AUTHZ-OVERVIEW-01] Close the false-green blind spot: a
        // ->only() entry naming a NON-EXISTENT method (e.g. 'overview' vs the real
        // 'salesReportOverview') string-matches here but never binds the middleware in Laravel
        // (it binds by handler method name). Assert the gated name is a real controller method.
        $this->assertContains(
            $method,
            get_class_methods($controller),
            "{$name}::{$method} does not exist — a ->only(['{$method}']) gate would silently never "
            . 'attach (Laravel binds controller middleware by the route handler method name).'
        );

        $middleware = $controller->getMiddleware();
        $gatedHere = false;

        foreach ($middleware as $entry) {
            $mw = $entry['middleware'] ?? null;
            $matches = $mw === $permission
                || (is_string($mw) && str_contains($mw, $permission))
                || (is_array($mw) && in_array($permission, $mw, true));
            if (! $matches) {
                continue;
            }
            $only = $entry['options']['only'] ?? null;
            $except = $entry['options']['except'] ?? null;
            // gated if: no only-restriction (applies to all), OR method is in only, OR (only set & method present), and not excepted
            $appliesToMethod = (empty($only) || in_array($method, (array) $only, true))
                && ! in_array($method, (array) $except, true);
            if ($appliesToMethod) {
                $gatedHere = true;
            }
        }

        $this->assertTrue(
            $gatedHere,
            "{$name}::{$method} must be gated by {$permission} (adversarial audit found it ungated). "
            . 'Add it to the existing ->only([...]) list.'
        );
    }
}
