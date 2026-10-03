<?php

namespace Tests\Unit\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [GOAL REMARQUES 2026-10-03 · T-1.7 R-075] Propriétaire, 20/08 : « les ticket que uber scan, chaque fois
 * ça donne art ! article non mappé !! alors vaut mieux l'afficher entièrement ! ».
 *
 * Une ligne Uber non reconnue est rattachée à l'article technique « Article Uber (non mappé) » : la
 * cuisine lisait « ART », et le vrai titre n'existait que dans la note « [UBER NON MAPPÉ: …] ». Le
 * titre devient la ligne produit, écrit en entier ; les options restent en symboles caisse ; la note
 * du client (« NO ONIONS ») reste visible. Jumeau : tests/js/kdsUberNonMappe.spec.js.
 */
class KitchenTicketUberNonMappeTitreTest extends TestCase
{
    private const INSTRUCTION = '[UBER NON MAPPÉ: Wrap Poulet Spicy] NO ONIONS';

    private function snapshot(): array
    {
        return ['lines' => [['attribute_name' => 'Sauce', 'variation_name' => 'Algérienne']], 'extras' => [], 'addons' => []];
    }

    public function test_la_ligne_produit_est_le_titre_uber_en_entier(): void
    {
        $f = new KitchenTicketSymbolicFormatter;

        $this->assertSame('WRAP POULET SPICY | ALG', $f->mainLine('Article Uber (non mappé)', $this->snapshot(), self::INSTRUCTION));
    }

    public function test_le_ticket_cuisine_imprime_le_titre_et_la_note_sans_le_marqueur(): void
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 0, 'tax_rate' => 10, 'tax_name' => 'TVA', 'tax_type' => 1, 'tax_amount' => 0,
            'instruction' => self::INSTRUCTION, 'composition_snapshot' => $this->snapshot(),
        ]);
        $oi->name = 'Article Uber (non mappé)';
        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-UBER', 'queue_number' => 'U0042', 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 0, 'total' => 0, 'pos_payment_method' => 1, 'order_datetime' => '2026-10-03 12:00:00', 'fiscal_sequence_no' => null,
        ]);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne', 'address' => 'x', 'phone' => '+33600000000']));
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        $b = app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order);

        $this->assertStringContainsString('WRAP POULET SPICY', $b);
        $this->assertStringContainsString('NO ONIONS', $b, 'la note capitale du client reste visible');
        $this->assertStringNotContainsString('UBER NON MAPP', $b, 'le marqueur technique ne s\'imprime plus : le titre EST la ligne produit');
        $this->assertDoesNotMatchRegularExpression('/\bART\b/', $b);
    }

    public function test_contre_epreuve_un_article_reconnu_garde_son_code(): void
    {
        $f = new KitchenTicketSymbolicFormatter;

        $this->assertSame('CAY | ALG', $f->mainLine('Cayenne', $this->snapshot(), 'NO ONIONS'));
    }
}
