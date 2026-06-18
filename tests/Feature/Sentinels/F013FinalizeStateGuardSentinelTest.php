<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID F-013 — finalizePaidKioskOrder state guard whitelist
 * @source .claude/worktrees/blissful-mclean-c915c2/plans/PLAN_AUDIT_F013_FINALIZE_STATE_GUARD_2026-05-07.md
 * @sprint Backlog (P3 — pattern hardening)
 *
 * Structural anti-regression for F-013.
 *
 * The fragile pre-fix guard `if ((int) $locked->status >= OrderStatus::ACCEPT) return;`
 * was numerically equivalent to the new whitelist `[OrderStatus::PENDING]` for
 * every status defined today, so a functional test alone cannot witness the
 * intent shift. This sentinel locks the source-level pattern :
 *
 *   1. The new whitelist `in_array(..., [OrderStatus::PENDING], true)` MUST be
 *      present inside `finalizePaidKioskOrder`.
 *   2. The legacy fragile `>= OrderStatus::ACCEPT` check inside
 *      `finalizePaidKioskOrder` MUST NOT reappear (no silent revert).
 *
 * If a future contributor removes the whitelist or reintroduces the `>= ACCEPT`
 * comparison in the same method, this sentinel goes red and the orchestrator
 * gate blocks the change.
 *
 * @see app/Services/FrontendOrderService.php finalizePaidKioskOrder
 */
class F013FinalizeStateGuardSentinelTest extends TestCase
{
    private function finalizeMethodSource(): string
    {
        $full = file_get_contents(base_path('app/Services/FrontendOrderService.php'));
        $start = strpos($full, 'public function finalizePaidKioskOrder');
        $this->assertNotFalse(
            $start,
            'F-013 sentinel: method finalizePaidKioskOrder must exist in FrontendOrderService.'
        );

        // Capture roughly the next 5000 chars — large enough to cover the
        // method body (~110 LOC) but short enough to avoid bleed into
        // adjacent methods.
        return substr($full, $start, 5000);
    }

    public function test_finalize_paid_kiosk_order_uses_explicit_pending_whitelist(): void
    {
        $body = $this->finalizeMethodSource();

        $this->assertMatchesRegularExpression(
            '/in_array\(\s*\(int\)\s*\$locked->status\s*,\s*\[\s*OrderStatus::PENDING\s*\]\s*,\s*true\s*\)/',
            $body,
            'F-013: finalizePaidKioskOrder must whitelist [OrderStatus::PENDING] explicitly '
            .'(intent-based guard, not fragile numeric comparison).'
        );
    }

    public function test_finalize_paid_kiosk_order_does_not_use_fragile_ge_accept_check(): void
    {
        $body = $this->finalizeMethodSource();

        $this->assertDoesNotMatchRegularExpression(
            '/\$locked->status\s*>=\s*OrderStatus::ACCEPT/',
            $body,
            'F-013: legacy fragile `$locked->status >= OrderStatus::ACCEPT` guard must not '
            .'reappear inside finalizePaidKioskOrder (would silently break if a future '
            .'intermediate status code is inserted between PENDING=1 and ACCEPT=4).'
        );
    }

    public function test_finalize_paid_kiosk_order_preserves_paid_payment_status_check(): void
    {
        $body = $this->finalizeMethodSource();

        // Defense-in-depth (F-21) must remain — never advance to ACCEPT without
        // confirmed payment, regardless of caller. F-013 must not regress F-21.
        $this->assertMatchesRegularExpression(
            '/\$locked->payment_status\s*!==?\s*PaymentStatus::PAID/',
            $body,
            'F-013 must not regress F-21: defense-in-depth payment_status==PAID check '
            .'must remain inside finalizePaidKioskOrder.'
        );
    }

    public function test_finalize_paid_kiosk_order_preserves_idempotent_fiscal_alloc_guard(): void
    {
        $body = $this->finalizeMethodSource();

        // F-001 idempotency : fiscal_sequence_no === null guard must remain so
        // a retry on an already-allocated order does not re-allocate a new
        // sequence number.
        $this->assertMatchesRegularExpression(
            '/\$locked->fiscal_sequence_no\s*===?\s*null/',
            $body,
            'F-013 must not regress F-001 idempotency: fiscal_sequence_no === null guard '
            .'must remain inside finalizePaidKioskOrder for retry-safe allocation.'
        );
    }

    public function test_F013_plan_remains_for_traceability(): void
    {
        // [prod-finale 2026-06-17] OBSOLETE traceability path: the plan doc lived in a SINCE-REMOVED session
        // worktree (.claude/worktrees/blissful-mclean-c915c2) and exists nowhere in this checkout. The actual
        // F-013 finalize-state-guard invariant is enforced by the other tests in this class. Skip the dead
        // doc-existence check (same class as F001/F009) rather than fake the artifact.
        $this->markTestSkipped('F-013 traceability doc lived in removed worktree blissful-mclean-c915c2; the finalize-state-guard invariant is covered by the other tests.');
        $this->assertFileExists(
            base_path('.claude/worktrees/blissful-mclean-c915c2/plans/PLAN_AUDIT_F013_FINALIZE_STATE_GUARD_2026-05-07.md'),
            'F-013 plan must remain available for traceability.'
        );
    }
}
