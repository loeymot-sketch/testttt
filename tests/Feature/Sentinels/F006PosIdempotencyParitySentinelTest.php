<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID F-006 — POS Idempotency Parity with Kiosk
 * @source plans/PLAN_AUDIT_F006_POS_IDEMPOTENCY_PARITY_2026-05-07.md
 * @sprint S3
 * @severity P1 — duplicate POS submit must return existing order, not 422
 *
 * Invariant Idempotency-parity (HANDOFF §invariants #5) :
 *   "POS et Kiosk ont le même comportement face à un duplicate `idempotency_key`
 *    (return existing 200, jamais 422)."
 *
 * Drift documenté dans reports/execution/audit_2026-05-07/REPORT_F006_pos_idempotency_parity.md :
 * Le plan F-006 §1 décrit l'état avant fix : pré-check naïf hors transaction +
 * catch generic Exception → 422 sur race / sequential duplicate.
 *
 * État runtime 2026-05-08 (drift) :
 *   - Pré-check existant via findExistingOrderForIdempotencyRecovery scoped (branch_id, key)
 *     (OrderService.php:567)
 *   - Catch QueryException 23000 + recovery présent (OrderService.php:1008-1020)
 *     introduit par commit `096aaab7d fix(order/idempotency): scope recovery lookup by branch`
 *     puis tagué [AUDIT-52-BUG6].
 *   - UNIQUE constraint composite (branch_id, idempotency_key) en place
 *     (database/migrations/2026_04_18_140003_scope_idempotency_key_to_branch.php).
 *
 * Résiduel : pas de Cache::lock préventif comme Kiosk myOrderStore (FrontendOrderService.php:141-145).
 *   → race contre la pré-vérification reste possible mais le 23000 catch la rattrape.
 *   → escalation décision orchestrateur : ajouter le lock ou clore F-006.
 *
 * Cette sentinel verrouille les invariants déjà en place pour empêcher toute
 * régression silencieuse de la parité POS↔Kiosk.
 */
class F006PosIdempotencyParitySentinelTest extends TestCase
{
    public function test_pos_orderstore_uses_branch_scoped_idempotency_recovery_helper(): void
    {
        $source = file_get_contents(base_path('app/Services/OrderService.php'));

        // posOrderStore doit utiliser findExistingOrderForIdempotencyRecovery (helper scoped branch)
        // et NON Order::where('idempotency_key', ...)->first() naïf.
        $this->assertMatchesRegularExpression(
            '/posOrderStore[\s\S]{0,2000}findExistingOrderForIdempotencyRecovery/',
            $source,
            'F-006: posOrderStore must use the branch-scoped findExistingOrderForIdempotencyRecovery '
            . 'helper for the pre-check (no raw Order::where(idempotency_key) lookup).'
        );
    }

    public function test_pos_orderstore_catches_queryexception_23000_and_returns_existing(): void
    {
        $source = file_get_contents(base_path('app/Services/OrderService.php'));

        // Le catch QueryException + recovery 23000 doit exister dans posOrderStore (race rattrapée DB).
        $this->assertMatchesRegularExpression(
            '/QueryException\s+\$qe[\s\S]{0,1500}[\'"]23000[\'"][\s\S]{0,1500}findExistingOrderForIdempotencyRecovery/',
            $source,
            'F-006: posOrderStore must catch QueryException with code 23000 and recover the existing '
            . 'order via findExistingOrderForIdempotencyRecovery (race past pre-check rescue).'
        );
    }

    public function test_pos_idempotency_key_truncated_to_64_chars_on_persist(): void
    {
        $source = file_get_contents(base_path('app/Services/OrderService.php'));

        // Plan §1 + §2 : key tronquée à 64 char avant persist (matches DB column length).
        $this->assertMatchesRegularExpression(
            "/substr\(\s*\\\$idempotencyKey\s*,\s*0\s*,\s*64\s*\)/",
            $source,
            'F-006: posOrderStore must truncate idempotency_key to 64 chars before persist.'
        );
    }

    public function test_findExistingOrderForIdempotencyRecovery_helper_is_branch_scoped(): void
    {
        $source = file_get_contents(base_path('app/Services/OrderService.php'));

        // Helper privé/protected qui scope par (idempotency_key, branch_id) — pas de leak cross-branch
        $this->assertMatchesRegularExpression(
            '/function\s+findExistingOrderForIdempotencyRecovery\([^)]*\$idempotencyKey[^)]*\$branchId[^)]*\)\s*:\s*\??Order/',
            $source,
            'F-006: helper must be defined with (key, branchId) signature returning ?Order.'
        );

        // Le corps doit clauser sur les 2 colonnes (where idempotency_key + where branch_id)
        $this->assertMatchesRegularExpression(
            "/where\(\s*['\"]idempotency_key['\"][\s\S]{0,200}where\(\s*['\"]branch_id['\"]/",
            $source,
            'F-006: helper body must filter on both idempotency_key AND branch_id (no cross-tenant leak).'
        );
    }

    public function test_orders_table_has_composite_unique_branch_id_idempotency_key(): void
    {
        $source = file_get_contents(base_path('database/migrations/2026_04_18_140003_scope_idempotency_key_to_branch.php'));

        $this->assertStringContainsString(
            "['branch_id', 'idempotency_key']",
            $source,
            'F-006: orders table must enforce composite UNIQUE (branch_id, idempotency_key) — backbone of the 23000 catch path.'
        );
    }

    public function test_kiosk_reference_pattern_still_in_place_for_parity_baseline(): void
    {
        $source = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        // Cache::lock préventif sur Kiosk myOrderStore — référence comparative
        $this->assertMatchesRegularExpression(
            "/Cache::lock\(\s*'frontend_order_idempotency_'/",
            $source,
            'F-006 parity baseline: Kiosk myOrderStore must keep its Cache::lock preventive serialization '
            . '(reference pattern POS is being aligned to).'
        );

        // Et le helper kiosk équivalent
        $this->assertMatchesRegularExpression(
            '/findExistingFrontendOrderForIdempotencyRecovery/',
            $source,
            'F-006 parity baseline: Kiosk recovery helper must remain in place.'
        );
    }

    public function test_F006_plan_exists_for_traceability(): void
    {
        // [prod-finale 2026-06-17] OBSOLETE traceability path: the plan doc lived in a SINCE-REMOVED session
        // worktree (.claude/worktrees/blissful-mclean-c915c2) and exists nowhere in this checkout. The actual
        // F-006 POS↔kiosk idempotency-parity invariant is enforced by the other tests in this class. Skip the
        // dead doc-existence check (same class as F001/F009) rather than fake the artifact.
        $this->markTestSkipped('F-006 traceability doc lived in removed worktree blissful-mclean-c915c2; the parity invariant is covered by the other tests.');
        $this->assertFileExists(
            base_path('.claude/worktrees/blissful-mclean-c915c2/plans/PLAN_AUDIT_F006_POS_IDEMPOTENCY_PARITY_2026-05-07.md'),
            'F-006 plan must remain available for traceability.'
        );
    }
}
