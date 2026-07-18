<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [MULTISAUCE 2026-07-18] Le 2e+ choix de sauce est envoyé par les wizards (frozen)
 * comme un ItemExtra GÉNÉRIQUE « Sauce supplémentaire » (prix seul, aucun nom). Le
 * nom réel ne survit que dans le texte libre `instruction` ("… Sauce : A, B" caisse /
 * "Sauces en plus : B" borne/web). Ces tests prouvent que le NOM de chaque sauce en
 * plus apparaît sur le ticket CLIENT + le ticket CUISINE + (via le formatter jumeau
 * du KDS), à l'identique de l'écran de paiement — SANS toucher le prix (déjà correct)
 * ni les zones frozen. Rétro-compatible : un snapshot sans instruction parsable rend
 * l'ancien libellé générique.
 */
class MultiSauceTicketNamesTest extends TestCase
{
    /**
     * Tacos M : 1ère sauce (variation gratuite nommée) + N sauce(s) en plus véhiculées
     * par l'extra générique « Sauce supplémentaire » @0,50. `$instruction` reproduit le
     * texte libre réel écrit par le wizard concerné (caisse / borne).
     */
    private function makeOrder(string $firstSauce, string $instruction, int $extraQty = 1): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne',
            'address' => '437 Rue Élie Gruyelle',
            'phone' => '+33600000000',
        ]);

        $oi = (new OrderItem)->forceFill([
            'quantity' => 1,
            'total_price' => 7.40,
            'tax_rate' => 10,
            'tax_name' => 'TVA',
            'tax_type' => 1,
            'tax_amount' => 0.67,
            'instruction' => $instruction,
            'composition_snapshot' => [
                'lines' => [
                    ['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => $firstSauce],
                    ['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné'],
                ],
                'extras' => [
                    ['extra_name' => 'Salade', 'line_total' => 0, 'quantity' => 1],
                    ['extra_name' => 'Sauce supplémentaire', 'line_total' => 0.50 * $extraQty, 'unit_price' => 0.50, 'quantity' => $extraQty],
                ],
                'addons' => [],
            ],
        ]);
        $oi->name = 'Tacos M';

        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-MS',
            'queue_number' => 'A0042',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 7.40,
            'total' => 7.40,
            'pos_payment_method' => 1,
            'pos_received_amount' => 7.40,
            'order_datetime' => '2026-07-18 12:00:00',
            'fiscal_sequence_no' => 3001,
        ]);
        $order->setRelation('branch', $branch);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return $order;
    }

    // ── Formatter (jumeau du KDS + source du ticket cuisine) ──────────────────

    /** @test */
    public function formatter_recovers_extra_sauce_names_caisse_format(): void
    {
        $f = new KitchenTicketSymbolicFormatter;
        // Caisse : "Sauce : <1ère>, <en plus...>" — la 1ère est gratuite, le reste = extras.
        $this->assertSame(
            ['Andalouse'],
            $f->extraSauceNames("TACOS M\nViandes : Poulet - Salade Sauce : Algérienne, Andalouse")
        );
        $this->assertSame(
            ['Andalouse', 'Blanche'],
            $f->extraSauceNames('Sauce : Algérienne, Andalouse, Blanche')
        );
    }

    /** @test */
    public function formatter_recovers_extra_sauce_names_borne_format(): void
    {
        $f = new KitchenTicketSymbolicFormatter;
        // Borne/web mono-ligne : "Sauces en plus : <extras>" — tout est déjà l'extra.
        $this->assertSame(
            ['Andalouse'],
            $f->extraSauceNames('Pain : Pain. Viandes : Poulet. Sauces en plus : Andalouse. Menu : complet')
        );
    }

    /** @test */
    public function formatter_ignores_frites_sauce_and_unparsable(): void
    {
        $f = new KitchenTicketSymbolicFormatter;
        // "Sauce frites :" est un autre canal (dip frites gratuit) → jamais capté ici.
        $this->assertSame([], $f->extraSauceNames('↳ Sauce frites: Andalouse'));
        $this->assertSame([], $f->extraSauceNames('Bien cuit svp'));
        $this->assertSame([], $f->extraSauceNames(''));
        // Une seule sauce (pas d'en-plus) → aucun extra.
        $this->assertSame([], $f->extraSauceNames('Sauce : Algérienne'));
    }

    /** @test */
    public function supplement_lines_name_the_generic_sauce_extra(): void
    {
        $f = new KitchenTicketSymbolicFormatter;
        $snap = ['extras' => [['extra_name' => 'Sauce supplémentaire', 'unit_price' => 0.50, 'quantity' => 1]]];
        $lines = $f->supplementLines($snap, 'Sauce : Algérienne, Andalouse');
        $this->assertSame(['+ Sauce supplémentaire : Andalouse'], $lines);

        // Rétro-compat : sans instruction parsable → libellé générique inchangé.
        $this->assertSame(['+ Sauce supplémentaire'], $f->supplementLines($snap, null));
    }

    // ── Ticket CLIENT (ESC/POS) ───────────────────────────────────────────────

    /** @test */
    public function client_ticket_shows_second_sauce_name_caisse(): void
    {
        $order = $this->makeOrder('Algérienne', "TACOS M\nViandes : Poulet mariné - Salade Sauce : Algérienne, Andalouse");
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);

        $this->assertStringContainsString('Andalouse', $bytes, 'La 2e sauce (Andalouse) doit être nommée sur le ticket client.');
        $this->assertStringContainsString('Sauce suppl', $bytes, 'La ligne extra sauce reste présente.');
        $this->assertStringContainsString('0,50', $bytes, 'Le prix de la sauce en plus est inchangé.');
    }

    /** @test */
    public function client_ticket_shows_second_sauce_name_borne(): void
    {
        $order = $this->makeOrder('Algérienne', 'TACOS M. Viandes : Poulet mariné. Sauces en plus : Andalouse.');
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);

        $this->assertStringContainsString('Andalouse', $bytes, 'Format borne : la sauce en plus doit être nommée.');
    }

    // ── Ticket CUISINE (ESC/POS) ──────────────────────────────────────────────

    /** @test */
    public function kitchen_ticket_shows_second_sauce_name(): void
    {
        $order = $this->makeOrder('Algérienne', "TACOS M\nViandes : Poulet mariné - Salade Sauce : Algérienne, Andalouse");
        $bytes = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($order);

        $this->assertStringContainsString('Andalouse', $bytes, 'La 2e sauce (Andalouse) doit apparaître sur le ticket cuisine.');
    }

    // ── Reproduction de la commande réelle #5727 ──────────────────────────────

    /** @test */
    public function reproduces_real_order_5727_algerienne_andalouse(): void
    {
        // OrderItem #5484 / commande #5727 : Tacos M, 2 sauces « Algérienne, Andalouse », 7,40 €.
        $order = $this->makeOrder(
            'Algérienne',
            "TACOS M\nViandes : Poulet mariné - Salade, Tomate, Oignon Sauce : Algérienne, Andalouse"
        );

        $client = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);
        $kitchen = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($order);

        $this->assertStringContainsString('Andalouse', $client, '#5727 : Andalouse absente du ticket client (bug racine).');
        $this->assertStringContainsString('Andalouse', $kitchen, '#5727 : Andalouse absente du ticket cuisine (bug racine).');
    }

    // ── Rétro-compatibilité ───────────────────────────────────────────────────

    /** @test */
    public function retro_compatible_generic_label_when_no_names(): void
    {
        // Ancien snapshot : extra générique mais instruction sans portion sauce parsable.
        $order = $this->makeOrder('Algérienne', 'TACOS M');
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);

        $this->assertStringContainsString('Sauce suppl', $bytes, 'Libellé générique conservé (rétro-compat).');
        $this->assertStringContainsString('0,50', $bytes, 'Prix inchangé.');
    }
}
