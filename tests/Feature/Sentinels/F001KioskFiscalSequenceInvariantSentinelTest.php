<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID F-001 — Kiosk Fiscal Sequence Allocation
 * @source plans/PLAN_AUDIT_F001_KIOSK_FISCAL_SEQUENCE_2026-05-07.md
 * @sprint S1
 * @severity P0 NF525 compliance
 *
 * Invariant NF525-Kiosk : `payment_status != UNPAID ⟹ fiscal_sequence_no IS NOT NULL` (FrontendOrder).
 *
 * Note drift documenté dans reports/execution/audit_2026-05-07/REPORT_F001_kiosk_fiscal_sequence.md :
 * Le plan F-001 §1.2 décrivait 2 paths :
 *   (a) Kiosk Cash : myOrderStore avec payment_status=PAID auto → drift résolu différemment :
 *       le path est devenu PENDING_COUNTER → caissier collecte via collectKioskCash →
 *       PaymentService::confirmCounterPayment alloue fiscal_seq dans OrderService.
 *   (b) Kiosk Card/Ticket : finalizePaidKioskOrder ligne 1021-1033 alloue fiscal_seq quand
 *       payment_status devient PAID.
 *
 * Cette sentinel verrouille les 2 paths (a et b) au niveau source pour empêcher toute
 * régression silencieuse de l'invariant NF525-Kiosk.
 */
class F001KioskFiscalSequenceInvariantSentinelTest extends TestCase
{
    public function test_path_b_finalizePaidKioskOrder_allocates_fiscal_sequence_when_PAID(): void
    {
        $source = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        // Le bloc d'allocation fiscal_seq doit être présent
        $this->assertStringContainsString(
            'FiscalSequenceService::class',
            $source,
            'F-001 path (b): FrontendOrderService must reference FiscalSequenceService for kiosk direct TPE.'
        );

        // L'allocation doit être conditionnée à fiscal_sequence_no === null (idempotent — pas de re-allocation si retry)
        $this->assertMatchesRegularExpression(
            '/fiscal_sequence_no\s*===?\s*null/',
            $source,
            'F-001 path (b): allocation must be guarded by null check (idempotent retry).'
        );

        // L'allocation doit invoquer next($branchId)
        $this->assertMatchesRegularExpression(
            '/->next\(\s*\(int\)\s*\$locked->branch_id\s*\)/',
            $source,
            'F-001 path (b): must call FiscalSequenceService::next((int) $locked->branch_id).'
        );

        // L'allocation doit logger sur channel fiscal pour audit trail
        $this->assertMatchesRegularExpression(
            "/Log::channel\(\s*'fiscal'\s*\)/",
            $source,
            'F-001 path (b): allocation must log on fiscal channel for audit trail.'
        );
    }

    public function test_path_a_counter_deferred_kiosk_cash_uses_PENDING_COUNTER(): void
    {
        $source = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        // Le path cash kiosk a évolué vers counter-deferred (drift documenté)
        $this->assertStringContainsString(
            'PENDING_COUNTER',
            $source,
            'F-001 path (a) drift: kiosk cash uses PENDING_COUNTER (collected later by cashier via collectKioskCash).'
        );

        $this->assertStringContainsString(
            'isCounterDeferredKioskCash',
            $source,
            'F-001 path (a) drift: flag isCounterDeferredKioskCash drives PENDING_COUNTER vs UNPAID branching.'
        );
    }

    public function test_path_a_completion_via_PaymentService_confirmCounterPayment_allocates_fiscal_seq(): void
    {
        $source = file_get_contents(base_path('app/Services/PaymentService.php'));

        // confirmCounterPayment DOIT allouer fiscal_seq via FiscalSequenceService
        $this->assertStringContainsString(
            'FiscalSequenceService',
            $source,
            'F-001 path (a) completion: PaymentService::confirmCounterPayment must allocate fiscal_seq for counter-deferred kiosk cash promotion.'
        );

        $this->assertStringContainsString(
            'OrderPaidAtCounter::dispatch',
            $source,
            'F-001 path (a) completion: confirmCounterPayment must dispatch OrderPaidAtCounter event for KDS notification.'
        );
    }

    public function test_collectKioskCash_route_invokes_confirmCounterPayment(): void
    {
        $source = file_get_contents(base_path('app/Services/OrderService.php'));

        // collectKioskCash est le pont entre FrontendOrder PENDING_COUNTER et la transition vers PAID
        $this->assertMatchesRegularExpression(
            '/collectKioskCash[\s\S]{0,300}confirmCounterPayment/',
            $source,
            'F-001 bridge: OrderService::collectKioskCash must invoke PaymentService::confirmCounterPayment to honor NF525 fiscal_seq allocation.'
        );
    }

    public function test_ZReportService_aggregate_filters_on_fiscal_sequence_no_not_null(): void
    {
        $source = file_get_contents(base_path('app/Services/Fiscal/ZReportService.php'));

        // L'invariant Z report : seuls les orders avec fiscal_sequence_no entrent dans l'aggregate
        $this->assertMatchesRegularExpression(
            "/whereNotNull\(\s*['\"]fiscal_sequence_no['\"]/",
            $source,
            'F-001 invariant: ZReportService::aggregate must filter on whereNotNull(fiscal_sequence_no) — kiosk paths must allocate or be excluded.'
        );
    }

    public function test_F001_plan_and_report_exist(): void
    {
        $this->assertFileExists(
            base_path('plans/PLAN_AUDIT_F001_KIOSK_FISCAL_SEQUENCE_2026-05-07.md'),
            'F-001 plan must remain available for traceability.'
        );
        $this->assertFileExists(
            base_path('reports/execution/audit_2026-05-07/REPORT_F001_kiosk_fiscal_sequence.md'),
            'F-001 execution report must be written before declaring closure.'
        );
    }
}
