<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Hardware\EscPosCommandBuilder;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [NOM-COMMANDE-BORNE 2026-10-19 · owner] « Je veux sur les tickets le montant, le numéro de commande
 * ainsi que le NOM » — borne, téléphone, site. Le nom saisi prime ; le site (client absent) reprend
 * le compte ; la borne et le comptoir n'empruntent JAMAIS le compte technique.
 */
class TicketNomClientTest extends TestCase
{
    private function order(string $surface, ?string $saisi, ?string $compte): Order
    {
        $oi = (new OrderItem)->forceFill(['quantity' => 1, 'total_price' => 8.9, 'tax_rate' => 10, 'tax_name' => 'TVA', 'tax_type' => 1, 'tax_amount' => 0.8, 'instruction' => '',
            'composition_snapshot' => ['lines' => [], 'extras' => [], 'addons' => []]]);
        $oi->name = 'Tacos M';
        $order = (new Order)->forceFill([
            'order_serial_no' => 'T-NOM', 'queue_number' => 'A0042', 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.9, 'total' => 8.9, 'pos_payment_method' => 1, 'order_datetime' => '2026-10-19 12:00:00',
            'fiscal_sequence_no' => 3003, 'source_surface' => $surface, 'pos_customer_name' => $saisi,
        ]);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne', 'address' => 'x', 'phone' => '+33600000000']));
        $order->setRelation('user', $compte === null ? null : (new User)->forceFill(['name' => $compte]));
        $order->setRelation('orderItems', collect([$oi]));

        return $order;
    }

    private function client(Order $o): string
    {
        return app(OrderReceiptEscPosRenderer::class)->renderClientTicket($o);
    }

    private function cuisine(Order $o): string
    {
        return app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($o);
    }

    public function test_le_nom_saisi_a_la_borne_sort_en_double_hauteur_gras_sur_le_ticket_client(): void
    {
        $b = $this->client($this->order('kiosk', 'Karim', 'Compte Borne Technique'));

        $this->assertStringContainsString(
            EscPosCommandBuilder::doubleHeight(true).EscPosCommandBuilder::bold(true).'Client : Karim',
            $b
        );
        $this->assertStringContainsString('A0042', $b, 'le numéro de commande reste imprimé');
        $this->assertStringContainsString('8,90', $b, 'le montant reste imprimé');
    }

    public function test_la_borne_sans_nom_n_emprunte_jamais_le_compte_technique(): void
    {
        $b = $this->client($this->order('kiosk', null, 'Compte Borne Technique'));
        // (le pied fiscal imprime « Caissier : <compte porteur> » — c'est une autre ligne, inchangée)
        $this->assertStringNotContainsString('Client :', $b);
        $this->assertStringNotContainsString('Client :', $this->cuisine($this->order('kiosk', null, 'Compte Borne Technique')));
    }

    public function test_une_commande_du_site_reprend_le_nom_du_compte_client_sur_les_deux_tickets(): void
    {
        $o = $this->order('web', null, 'Sofia Martin');
        $this->assertStringContainsString('Client : Sofia Martin', $this->client($o));
        $this->assertStringContainsString('Client : Sofia Martin', $this->cuisine($o));
    }

    public function test_un_nom_saisi_prime_sur_le_compte_meme_pour_le_site(): void
    {
        $o = $this->order('web', 'Sofia', 'Sofia Martin');
        $b = $this->client($o);
        $this->assertStringContainsString('Client : Sofia', $b);
        $this->assertStringNotContainsString('Client : Sofia Martin', $b);
    }

    public function test_la_caisse_sans_nom_n_emprunte_pas_le_compte_du_caissier(): void
    {
        $b = $this->client($this->order('pos', null, 'Caissier Admin'));
        $this->assertStringNotContainsString('Client :', $b);
    }
}
