<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\EscPosCommandBuilder;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [GOAL CAISSE/CUISINE #7 2026-10-02] Lisibilité cuisine sur le TICKET IMPRIMÉ :
 *  - la ligne d'un produit qui porte un supplément COMMENCE par un « # » gras ;
 *  - chaque supplément s'écrit en gras, BLANC sur cadre NOIR (GS B — lecture inversée) ;
 *  - une ligne SANS supplément ne porte ni « # » ni cadre inversé.
 */
class KitchenTicketLisibiliteInverseeTest extends TestCase
{
    private function order(array $extras, string $instruction = ''): Order
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 8.9, 'tax_rate' => 10, 'tax_name' => 'TVA', 'tax_type' => 1, 'tax_amount' => 0.8,
            'instruction' => $instruction,
            'composition_snapshot' => [
                'lines' => [['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Algérienne']],
                'extras' => $extras,
                'addons' => [],
            ],
        ]);
        $oi->name = 'Tacos M';
        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-LI', 'queue_number' => 'A0042', 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.9, 'total' => 8.9, 'pos_payment_method' => 1, 'order_datetime' => '2026-10-02 12:00:00', 'fiscal_sequence_no' => 3001,
        ]);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne', 'address' => 'x', 'phone' => '+33600000000']));
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return $order;
    }

    private function bytes(Order $o): string
    {
        return app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($o);
    }

    public function test_la_ligne_produit_a_supplement_commence_par_un_diese_gras(): void
    {
        $b = $this->bytes($this->order([['extra_name' => 'Cheddar', 'unit_price' => 0.9, 'line_total' => 0.9, 'quantity' => 1]]));

        $this->assertStringContainsString(EscPosCommandBuilder::bold(true).'  # Tacos', $b, 'la ligne produit doit commencer par « # » en gras');
    }

    public function test_chaque_supplement_est_blanc_sur_noir(): void
    {
        $b = $this->bytes($this->order([
            ['extra_name' => 'Cheddar', 'unit_price' => 0.9, 'line_total' => 0.9, 'quantity' => 1],
            ['extra_name' => 'Boursin', 'unit_price' => 0.9, 'line_total' => 0.9, 'quantity' => 1],
        ]));

        foreach (['Cheddar', 'Boursin'] as $nom) {
            $this->assertStringContainsString(
                EscPosCommandBuilder::invert(true).' '.$nom.' '.EscPosCommandBuilder::invert(false),
                $b,
                "« $nom » doit être imprimé en lecture inversée (blanc sur noir)"
            );
        }
        $this->assertStringNotContainsString('* Cheddar', $b, "l'ancienne étoile n'est plus le signal");
        $this->assertSame(substr_count($b, EscPosCommandBuilder::invert(true)), substr_count($b, EscPosCommandBuilder::invert(false)), "l'inversion doit toujours être refermée");
    }

    public function test_une_ligne_sans_supplement_n_a_ni_diese_ni_cadre_noir(): void
    {
        $b = $this->bytes($this->order([['extra_name' => 'Salade', 'unit_price' => 0, 'line_total' => 0, 'quantity' => 1]]));

        $this->assertStringNotContainsString('# Tacos', $b);
        $this->assertStringNotContainsString(EscPosCommandBuilder::invert(true), $b);
    }
}
