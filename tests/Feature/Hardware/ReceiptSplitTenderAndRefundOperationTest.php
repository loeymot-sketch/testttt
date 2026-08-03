<?php

namespace Tests\Feature\Hardware;

use App\Enums\OrderStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * Pure (no-DB) coverage for two NF525 paper-ticket defects on the ESC/POS client ticket :
 *   - [MP-02] paiement SPLIT : le ticket n'imprimait qu'UNE ligne tender (méthode dominante)
 *     → le règlement affiché divergeait du total. Doit imprimer CHAQUE tranche.
 *   - [MP-04] reçu d'un remboursement (miroir RTN-, status=RETURNED + parent_order_id, totaux
 *     négatifs) imprimé « Operation : VENTE » → doit dire « Operation : REMBOURSEMENT ».
 */
class ReceiptSplitTenderAndRefundOperationTest extends TestCase
{
    private function makeOrder(array $overrides = []): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne',
            'address' => '437 Rue Élie Gruyelle',
            'phone' => '+33600000000',
        ]);

        $oi = (new OrderItem)->forceFill([
            'quantity' => 1,
            'total_price' => 15.50,
            'tax_rate' => 10,
            'tax_name' => 'TVA',
            'tax_type' => 1,
            'tax_amount' => 1.41,
            'composition_snapshot' => ['lines' => [], 'extras' => [], 'addons' => []],
        ]);
        $oi->name = 'Tacos XL';

        $order = (new Order)->forceFill(array_merge([
            'order_serial_no' => 'TEST-SPLIT-1',
            'queue_number' => 'A0011',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'status' => OrderStatus::DELIVERED,
            'subtotal' => 15.50,
            'total' => 15.50,
            'total_tax' => 1.41,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_datetime' => '2026-07-22 12:30:00',
            'fiscal_sequence_no' => 2601,
        ], $overrides));
        $order->setRelation('branch', $branch);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return $order;
    }

    /**
     * [MP-02] Split ESPÈCES 11,00 + CARTE 4,50 sur un total de 15,50 : les DEUX tranches doivent
     * apparaître (label + montant). Avant le fix, seule la méthode dominante (ESPÈCES) sortait.
     */
    public function test_split_payment_prints_each_tender_line(): void
    {
        $order = $this->makeOrder();
        $order->setRelation('payments', collect([
            (new OrderPayment)->forceFill(['mode' => PosPaymentMethod::CASH, 'amount' => 11.00, 'change_amount' => 0]),
            (new OrderPayment)->forceFill(['mode' => PosPaymentMethod::CARD, 'amount' => 4.50, 'change_amount' => 0]),
        ]));

        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);

        // Tranche CARTE : label + montant. 'CARTE' et '4,50' sont ABSENTS avant le fix (ligne unique ESPÈCES).
        $this->assertStringContainsString('CARTE', $bytes, 'La tranche CARTE doit être imprimée sur un split.');
        $this->assertStringContainsString('4,50', $bytes, 'Le montant de la tranche CARTE (4,50) doit apparaître.');
        // Tranche ESPÈCES : préfixe ASCII 'ESP' (È = octet CP858) + montant réel de la tranche (11,00, pas le total 15,50).
        $this->assertStringContainsString('ESP', $bytes, 'La tranche ESPÈCES doit être imprimée.');
        $this->assertStringContainsString('11,00', $bytes, 'La tranche ESPÈCES doit afficher 11,00 (montant tranche, pas le total).');
    }

    /** [MP-04] Une vente normale reste marquée « Operation : VENTE ». */
    public function test_normal_sale_marks_operation_vente(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeOrder());

        $this->assertStringContainsString('Operation : VENTE', $bytes, 'Une vente doit être marquée VENTE.');
        $this->assertStringNotContainsString('REMBOURSEMENT', $bytes);
    }

    /**
     * [MP-04] Le reçu d'un miroir de remboursement (status=RETURNED + parent_order_id, total négatif,
     * serial RTN-) doit être marqué « Operation : REMBOURSEMENT » — jamais « VENTE ».
     */
    public function test_refund_mirror_marks_operation_remboursement(): void
    {
        $order = $this->makeOrder([
            'order_serial_no'    => 'RTN-TEST-SPLIT-1',
            'status'             => OrderStatus::RETURNED,
            'parent_order_id'    => 4242,
            'subtotal'           => -15.50,
            'total'              => -15.50,
            'total_tax'          => -1.41,
        ]);

        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);

        $this->assertStringContainsString('Operation : REMBOURSEMENT', $bytes, 'Un remboursement doit être marqué REMBOURSEMENT (NF525 : type d\'opération exact).');
        $this->assertStringNotContainsString('Operation : VENTE', $bytes, 'Un remboursement ne doit JAMAIS être marqué VENTE.');
    }
}
