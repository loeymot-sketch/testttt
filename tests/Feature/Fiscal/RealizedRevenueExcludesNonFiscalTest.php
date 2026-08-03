<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [SELF-AUDIT R3 P1 2026-07-05] scopeRealizedRevenue / isRealizedRevenueRow sont DOCUMENTÉS « mirror the
 * signed ZReportService netting » (Order.php:300-307). Or ZReportService::aggregate exige
 * whereNotNull(fiscal_sequence_no) : une commande Uber (PAID, non-terminale, fiscal_sequence_no NULL car
 * facturée séparément par l'agrégateur) comptait dans le CA management mais PAS dans le Z → dashboard/
 * sales-report/EOD > Z signé. Ce test verrouille l'alignement : seule une vente SCELLÉE compte.
 */
class RealizedRevenueExcludesNonFiscalTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_fiscal_uber_order_is_excluded_from_realized_revenue(): void
    {
        $branch = Branch::factory()->create();

        // Uber : PAID, non-terminale, AUCUN fiscal_sequence_no (canal séparé).
        $uber = Order::factory()->create([
            'branch_id' => $branch->id,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
            'fiscal_sequence_no' => null,
            'source_surface' => 'uber_eats',
            'total' => 20.00,
        ]);

        // Vente POS SCELLÉE : PAID + fiscal alloué.
        $pos = Order::factory()->create([
            'branch_id' => $branch->id,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
            'fiscal_sequence_no' => 1,
            'total' => 10.00,
        ]);

        // Collection-side mirror.
        $this->assertFalse(Order::isRealizedRevenueRow($uber), 'Uber non-fiscalisé exclu du CA réconcilié Z.');
        $this->assertTrue(Order::isRealizedRevenueRow($pos), 'Vente POS scellée comptée.');

        // Query-side scope.
        $ids = Order::query()->realizedRevenue()->pluck('id')->all();
        $this->assertNotContains($uber->id, $ids, 'Le scope exclut aussi la commande Uber non-fiscalisée.');
        $this->assertContains($pos->id, $ids, 'Le scope garde la vente scellée.');

        // Le CA réconcilié = 10,00 (POS) et NON 30,00 (POS + Uber) → cohérent avec le Z signé.
        $this->assertEqualsWithDelta(10.00, (float) Order::query()->realizedRevenue()->sum('total'), 0.01);
    }

    public function test_refund_mirror_still_counts_negatively(): void
    {
        $branch = Branch::factory()->create();
        $parent = Order::factory()->create([
            'branch_id' => $branch->id, 'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT, 'fiscal_sequence_no' => 1, 'total' => 15.00,
        ]);
        $mirror = Order::factory()->create([
            'branch_id' => $branch->id, 'status' => OrderStatus::RETURNED,
            'parent_order_id' => $parent->id, 'fiscal_sequence_no' => 2, 'total' => -15.00,
        ]);

        $this->assertTrue(Order::isRealizedRevenueRow($mirror), 'Le miroir de remboursement reste compté (total négaté).');
        $this->assertEqualsWithDelta(0.00, (float) Order::query()->realizedRevenue()->sum('total'), 0.01, 'Vente + remboursement = ~0, comme le Z.');
    }
}
