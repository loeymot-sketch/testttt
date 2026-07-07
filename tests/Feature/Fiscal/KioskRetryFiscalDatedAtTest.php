<?php

namespace Tests\Feature\Fiscal;

use Tests\TestCase;

/**
 * [NF-01 — LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT 2026-07-07]
 *
 * Le 1er fix P1 (fiscal_dated_at) a stampé confirmCounterPayment + les 2 arêtes
 * UNPAID→PAID d'OrderService, mais a OUBLIÉ le chemin kiosk-payé
 * (FrontendOrderService::finalizePaidKioskOrder), atteignable en ALLOCATION
 * DIFFÉRÉE via RetryFiscalAllocCommand / PaymentReconcile. Sans le stamp, une
 * commande kiosk dont l'alloc échoue à T0 puis est rattrapée dans un Z ultérieur
 * tombait hors de TOUT Z signé (P1 recréé + invisible au détecteur). Ce test
 * verrouille : le chemin kiosk stampe fiscal_dated_at à l'allocation, comme les
 * 3 autres. Contrat-source (les 4 sites d'allocation différée stampent tous).
 */
class KioskRetryFiscalDatedAtTest extends TestCase
{
    public function test_kiosk_fiscal_allocation_path_stamps_fiscal_dated_at(): void
    {
        $src = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        // Le stamp doit exister dans le chemin kiosk (garde hasColumn + now()).
        $this->assertStringContainsString(
            "\$locked->fiscal_dated_at = now();",
            $src,
            'finalizePaidKioskOrder doit stamper fiscal_dated_at à l\'allocation (NF-01).'
        );
        $this->assertStringContainsString(
            "Schema::hasColumn('orders', 'fiscal_dated_at')",
            $src,
            'Le stamp kiosk doit être gardé par hasColumn (dégradation propre si colonne absente).'
        );
    }

    public function test_all_deferred_allocation_sites_stamp_fiscal_dated_at(): void
    {
        // Les 4 chemins d'allocation DIFFÉRÉE (fiscal alloué après création) stampent tous.
        // Les 2 sûrs (POS synchrone OrderService:~1181, refund-mirror) ont created_at=alloc.
        $paths = [
            'app/Services/PaymentService.php'       => 1, // confirmCounterPayment
            'app/Services/OrderService.php'         => 2, // COD@doorstep + marquer-payé
            'app/Services/FrontendOrderService.php' => 1, // kiosk-payé (NF-01)
        ];
        foreach ($paths as $file => $minCount) {
            $src = file_get_contents(base_path($file));
            $count = substr_count($src, '$locked->fiscal_dated_at = now();');
            $this->assertGreaterThanOrEqual(
                $minCount,
                $count,
                "$file doit stamper fiscal_dated_at sur ses chemins d'allocation différée (au moins $minCount)."
            );
        }
    }
}
