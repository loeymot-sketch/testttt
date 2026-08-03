<?php

namespace Tests\Feature\Fiscal;

use App\Models\Order;
use Tests\TestCase;

/**
 * [GOAL-2026-05-29 FISCAL-P1] NF525 Z-report HT/TVA/TTC decomposition.
 *
 * ZReportService::applyOrderToTotals reads `$order->total_ht ?? $order->subtotal`.
 * There was no total_ht column/accessor → it fell back to `subtotal`, which in
 * tax-inclusive mode is a TTC figure → the signed/archived z_reports row carried
 * a wrong decomposition (total_ht + total_tva != total_ttc). The new
 * Order::getTotalHtAttribute derives HT from the SAME amount the Z uses for TTC
 * (`total`) minus the TVA, so the legal identity TTC = HT + TVA holds by
 * construction. This locks it.
 */
class OrderTotalHtDecompositionTest extends TestCase
{
    public function test_total_ht_is_total_minus_tax_so_ttc_equals_ht_plus_tva(): void
    {
        $order = new Order();
        $order->setRawAttributes(['total' => 12.50, 'total_tax' => 2.50]);

        $this->assertSame(10.00, $order->total_ht, 'HT = total - TVA.');
        // NF525 legal identity: TTC = HT + TVA (the exact aggregation
        // ZReportService performs: total_ttc=Σtotal, total_tva=Σtotal_tax,
        // total_ht=Σtotal_ht).
        $this->assertEqualsWithDelta(12.50, $order->total_ht + 2.50, 0.001);
    }

    public function test_total_ht_with_zero_tax_equals_total(): void
    {
        $order = new Order();
        $order->setRawAttributes(['total' => 7.00, 'total_tax' => 0.00]);

        $this->assertSame(7.00, $order->total_ht);
    }

    public function test_total_ht_defaults_to_zero_on_empty_order(): void
    {
        $this->assertSame(0.0, (new Order())->total_ht);
    }
}
