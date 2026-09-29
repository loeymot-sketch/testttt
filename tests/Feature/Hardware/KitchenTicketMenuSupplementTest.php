<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [FIX-1 2026-08-25 · P0 cuisine, constat E-002] UN SUPPLÉMENT PAYÉ SUR UNE LIGNE
 * « CONTENEUR DE MENU » ATTEINT LA CUISINE.
 *
 * La branche `isMenuItem` du rendu cuisine construisait son bloc avec
 * `'supps' => []` en dur : la boisson et le badge sortaient, les suppléments JAMAIS.
 * Un cheddar facturé sur une ligne « Menu (Frites + Boisson) » n'apparaissait donc
 * ni à l'écran (kdsSymbolic.js, même trou) ni sur le papier — sans marqueur, sans
 * trace, juste du blanc à sa place.
 *
 * La règle owner [KITCHEN-MENU 2026-06-30] visait le DÉTAIL de la formule
 * (« Frites + Boisson ») et le PRIX — pas un extra payé, qui est du travail à faire
 * en plus. Le badge MENU reste inchangé ; on ajoute ce qui manquait.
 *
 * Jumeau écran : tests/js/kdsExtraJamaisEscamote.spec.js (« TROU B »).
 */
class KitchenTicketMenuSupplementTest extends TestCase
{
    /** @test */
    public function un_supplement_paye_sur_une_ligne_de_menu_est_imprime(): void
    {
        $order = $this->makeMenuOrder([
            ['extra_name' => 'Cheddar', 'quantity' => 2, 'unit_price' => 0.9, 'line_total' => 1.8],
        ]);

        $kitchen = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($order);

        $this->assertStringContainsString('MENU', $kitchen, 'le badge de formule reste');
        $this->assertStringContainsString('Coca-Cola 33cl', $kitchen, 'la boisson reste');
        $this->assertStringContainsString('Cheddar', $kitchen, 'le supplément payé ne disparaît plus');
    }

    /** @test */
    public function un_supplement_sans_nom_sur_une_ligne_de_menu_est_annonce(): void
    {
        $order = $this->makeMenuOrder([
            ['extra_id' => 269, 'quantity' => 1, 'unit_price' => 0.9, 'line_total' => 0.9],
        ]);

        $kitchen = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($order);

        $this->assertStringContainsString('Suppl', $kitchen, 'un extra anonyme reste annoncé');
    }

    /** @param array<int,array<string,mixed>> $extras */
    private function makeMenuOrder(array $extras): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne', 'address' => '437 Rue Élie Gruyelle', 'phone' => '+33600000000',
        ]);

        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 2.50, 'tax_rate' => 10, 'tax_name' => 'TVA',
            'tax_type' => 1, 'tax_amount' => 0.23, 'instruction' => '',
            'composition_snapshot' => [
                'lines' => [],
                'extras' => $extras,
                'addons' => [
                    ['role' => 'menu_boisson', 'addon_name' => 'Coca-Cola 33cl', 'quantity' => 1],
                ],
            ],
        ]);
        $oi->name = 'Menu (Frites + Boisson)';

        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-MENU-SUPP', 'queue_number' => 'A0091',
            'order_type' => \App\Enums\OrderType::TAKEAWAY, 'subtotal' => 2.50, 'total' => 2.50,
            'pos_payment_method' => 1, 'pos_received_amount' => 2.50,
            'order_datetime' => '2026-08-25 12:00:00', 'fiscal_sequence_no' => 3101,
        ]);
        $order->setRelation('branch', $branch);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return $order;
    }
}
