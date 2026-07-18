<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\MessageController;
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
        // [P1-5 audit 2026-07-18] La MÉTHODE est `salesReportOverview` (l'URI est
        // /overview). Tester 'overview' rendait cette sentinelle VERTE À TORT tant
        // que ->only('overview') ne matchait aucune méthode. On teste le vrai nom.
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

    private function assertMethodGated(object $controller, string $permission, string $method, string $name): void
    {
        // [P1-5 audit 2026-07-18] Garde anti-vert-faux : la méthode citée doit
        // EXISTER sur le contrôleur, sinon ->only(<nom>) ne gate rien et le test
        // passe pour de mauvaises raisons (le bug exact de REP-AUTHZ-01).
        $this->assertTrue(
            method_exists($controller, $method),
            "{$name}::{$method} n'existe pas — un ->only() sur ce nom ne garderait aucune route."
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
