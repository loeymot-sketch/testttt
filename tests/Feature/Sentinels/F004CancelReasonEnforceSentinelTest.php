<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID    F-004 — Cancel reason enforcement
 * @source   .claude/worktrees/blissful-mclean-c915c2/plans/PLAN_AUDIT_F004_CANCEL_REASON_ENFORCE_2026-05-07.md
 * @sprint   S3
 * @severity P1 — Audit trail completeness on terminal transitions
 *
 * Invariant : aucune transition vers CANCELED / REJECTED / RETURNED ne doit s'inscrire
 * dans `order_status_transitions` avec `reason = NULL`.
 *
 * Verrous structurels (source-level) protégeant la chaîne enforcement :
 *   1. Enum OrderCancelReason existe et expose isValid() + all().
 *   2. OrderStatusRequest valide non-vide pour status terminaux.
 *   3. OrderStatusRequest applique whitelist enum quand l'actor est kiosk machine token.
 *   4. FrontendOrderService::changeStatus propage `$request->reason` (et non `null`)
 *      à OrderStateMachine::recordTransition.
 */
class F004CancelReasonEnforceSentinelTest extends TestCase
{
    public function test_enum_class_exists_and_exposes_required_api(): void
    {
        $this->assertTrue(
            class_exists(\App\Enums\OrderCancelReason::class),
            'F-004: OrderCancelReason enum must exist for kiosk whitelist.'
        );

        $this->assertTrue(
            method_exists(\App\Enums\OrderCancelReason::class, 'all'),
            'F-004: OrderCancelReason::all() required by Request validator.'
        );

        $this->assertTrue(
            method_exists(\App\Enums\OrderCancelReason::class, 'isValid'),
            'F-004: OrderCancelReason::isValid() required by Request validator.'
        );

        // Codes minimaux requis pour le frontend kiosk (TPE + customer).
        $required = ['tpe_cancel_user', 'tpe_declined', 'tpe_timeout', 'customer_request'];
        $codes = \App\Enums\OrderCancelReason::all();
        foreach ($required as $code) {
            $this->assertContains(
                $code,
                $codes,
                "F-004: OrderCancelReason::all() must contain '{$code}' for kiosk path mapping."
            );
        }
    }

    public function test_request_enforces_non_empty_reason_for_terminal_transitions(): void
    {
        $source = file_get_contents(base_path('app/Http/Requests/OrderStatusRequest.php'));

        $this->assertStringContainsString(
            'OrderCancelReason',
            $source,
            'F-004: OrderStatusRequest must reference OrderCancelReason for whitelist enforcement.'
        );

        $this->assertMatchesRegularExpression(
            '/CANCELED.*REJECTED.*RETURNED/s',
            $source,
            'F-004: OrderStatusRequest must enumerate the three terminal statuses.'
        );

        $this->assertStringContainsString(
            "errors()->add('reason'",
            $source,
            'F-004: OrderStatusRequest must add a reason validation error on missing/invalid input.'
        );
    }

    public function test_request_whitelists_only_for_kiosk_machine_token_actor(): void
    {
        $source = file_get_contents(base_path('app/Http/Requests/OrderStatusRequest.php'));

        // Whitelist enforcement must be guarded by tokenCan('kiosk:order') so admin/staff
        // free-text reasons (PosOrderBL2 audit log) keep working.
        $this->assertMatchesRegularExpression(
            "/tokenCan\(\s*'kiosk:order'\s*\)/",
            $source,
            'F-004: OrderStatusRequest whitelist gate must check tokenCan(kiosk:order).'
        );

        $this->assertStringContainsString(
            'OrderCancelReason::isValid',
            $source,
            'F-004: OrderStatusRequest must call OrderCancelReason::isValid for kiosk-token actors.'
        );
    }

    public function test_frontend_order_service_propagates_reason_to_state_machine(): void
    {
        $source = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        // The cancel block must NOT pass `null` as the reason argument anymore.
        $this->assertMatchesRegularExpression(
            '/recordTransition\(\s*FrontendOrder::class,[\s\S]{0,400}?\$cancelReason\s*\)/',
            $source,
            'F-004: FrontendOrderService::changeStatus must pass $cancelReason (not null) to recordTransition.'
        );

        $this->assertStringContainsString(
            "AUDIT-F-004",
            $source,
            'F-004: FrontendOrderService cancel block must carry the AUDIT-F-004 traceability marker.'
        );

        // Ensure the literal `null` parameter pattern in the kiosk cancel branch is gone.
        $this->assertDoesNotMatchRegularExpression(
            '/recordTransition\(\s*FrontendOrder::class,[\s\S]{0,400}?Auth::id\(\)\s*:\s*null\s*\)\s*,\s*\n\s*null/',
            $source,
            'F-004: kiosk cancel branch must not call recordTransition(..., null) anymore.'
        );
    }
}
