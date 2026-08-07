<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID F-013 — finalizePaidKioskOrder state guard whitelist
 * @source plans/PLAN_AUDIT_F013_FINALIZE_STATE_GUARD_2026-05-07.md
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

        // [HEAL 2026-08-07] Auparavant : `substr($full, $start, 5000)`. Fenêtre FIXE, donc
        // périmée dès que la méthode grossit — et c'est arrivé : le garde
        // `fiscal_sequence_no === null` s'est retrouvé à 5904 caractères du début, HORS
        // fenêtre. La sentinelle criait à la régression fiscale alors que le garde était
        // intact. Une sentinelle qui rougit à tort finit ignorée, ce qui est pire que pas
        // de sentinelle du tout sur un invariant NF525.
        //
        // On borne désormais sur la DÉCLARATION DE MÉTHODE SUIVANTE : la fenêtre suit la
        // taille réelle du corps, sans jamais déborder sur la méthode d'à côté.
        $end = strlen($full);
        foreach (['public function ', 'private function ', 'protected function '] as $next) {
            $candidate = strpos($full, "\n    ".$next, $start + 1);
            if ($candidate !== false && $candidate < $end) {
                $end = $candidate;
            }
        }

        $body = substr($full, $start, $end - $start);

        // Garde anti-fenêtre-vide : si l'extraction ne rend presque rien, l'assertion
        // suivante passerait ou échouerait pour la mauvaise raison.
        $this->assertGreaterThan(
            2000,
            strlen($body),
            'F-013 sentinel: extraction du corps de finalizePaidKioskOrder anormalement courte — '
            .'le repérage de la méthode suivante a échoué, la sentinelle ne prouve plus rien.'
        );

        return $body;
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
        $this->assertFileExists(
            base_path('plans/PLAN_AUDIT_F013_FINALIZE_STATE_GUARD_2026-05-07.md'),
            'F-013 plan must remain available for traceability.'
        );
    }
}
