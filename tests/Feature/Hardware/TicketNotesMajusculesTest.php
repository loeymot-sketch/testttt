<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [REVUE ADVERSE 2026-10-19 · P0] Une note du client tapée EN MAJUSCULES (verrouillage majuscules d'un
 * téléphone, commande du site) était jetée du ticket cuisine comme « écho du nom du produit ». Une note
 * d'allergène pouvait ainsi disparaître en silence. L'écho du wizard (« TACOS ») reste, lui, supprimé.
 */
class TicketNotesMajusculesTest extends TestCase
{
    private function ticket(string $instruction): string
    {
        $oi = (new OrderItem)->forceFill(['quantity' => 1, 'total_price' => 8.9, 'tax_rate' => 10, 'tax_name' => 'TVA', 'tax_type' => 1, 'tax_amount' => 0.8,
            'instruction' => $instruction, 'composition_snapshot' => ['lines' => [], 'extras' => [], 'addons' => []]]);
        $oi->name = 'Tacos M';
        $order = (new Order)->forceFill(['order_serial_no' => 'T-MAJ', 'queue_number' => 'A0042', 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.9, 'total' => 8.9, 'pos_payment_method' => 1, 'order_datetime' => '2026-10-19 12:00:00', 'fiscal_sequence_no' => 3004, 'source_surface' => 'web']);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne', 'address' => 'x', 'phone' => '+33600000000']));
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order);
    }

    public function test_une_note_de_consigne_en_majuscules_est_imprimee(): void
    {
        // (une note longue s'enroule en double largeur : on contrôle chaque MOT, pas la ligne entière)
        foreach (['SANS OEUF', 'ALLERGIE ARACHIDES', 'PAS DE MAYONNAISE SVP', 'NO ONION', 'BIEN CUIT'] as $note) {
            $b = $this->ticket($note);
            foreach (explode(' ', $note) as $mot) {
                $this->assertStringContainsString($mot, $b, "le mot « $mot » de la note « $note » doit sortir sur le ticket cuisine");
            }
        }
    }

    public function test_l_echo_du_nom_du_produit_en_majuscules_reste_supprime(): void
    {
        $b = $this->ticket("TACOS\nSANDWICH CAYENNE");

        $this->assertStringNotContainsString('SANDWICH CAYENNE', $b);
        $this->assertStringNotContainsString('** TACOS', $b);
    }

    public function test_les_notes_en_minuscules_ou_entre_crochets_sont_toujours_imprimees(): void
    {
        $this->assertStringContainsString('sans oeuf', $this->ticket('sans oeuf'));
        $this->assertStringContainsString('SANS OEUF', $this->ticket('[SANS OEUF]'));
    }
}
