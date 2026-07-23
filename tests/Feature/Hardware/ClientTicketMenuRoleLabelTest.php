<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [MENU-ROLE-CLIENT 2026-07-23] Le ticket CLIENT doit refléter le CHOIX RÉEL de formule.
 *
 * Sur la borne une formule = UN seul addon « Menu (Frites + Boisson) » (item id 1) ; le
 * choix réel du client (complet / frites seules / boisson seule) est porté UNIQUEMENT par
 * le `role` du composition_snapshot (menu_full / menu_frites / menu_boisson), l'addon_name
 * étant scellé IDENTIQUE pour les 3 + le line_total déjà proratisé (NF525, PricingService).
 *
 * Le ticket CUISINE décodait déjà ce rôle (KitchenTicketSymbolicFormatter::menuLine →
 * MENU / FRITES / BOISSON) ; le ticket CLIENT, lui, imprimait le nom d'addon BRUT — donc
 * « Menu (Frites + Boisson) » même pour une frite seule, avec le prix menu collé dessus
 * (perçu « anormal » par l'owner). Ce test verrouille la PARITÉ côté ticket CLIENT :
 *   - menu_frites  → « Menu Frites »          (pas le nom brut)
 *   - menu_boisson → « Menu Boisson »
 *   - menu_full    → « Menu Frites + Boisson »
 * Garde-fou : un produit STANDALONE (« Frites Seules », SANS addon menu_*) garde son nom
 * d'article intact. Le prix imprimé reste le line_total SCELLÉ — jamais recalculé ici.
 */
class ClientTicketMenuRoleLabelTest extends TestCase
{
    /**
     * Commande formule : produit de base + UN addon menu scellé « Menu (Frites + Boisson) »,
     * dont seul le `role` distingue le choix réel (miroir exact du snapshot borne).
     */
    private function makeFormulaOrder(string $role, float $addonTotal): Order
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1,
            'total_price' => 8.00 + $addonTotal,
            'tax_rate' => 10,
            'tax_name' => 'TVA',
            'tax_type' => 1,
            'tax_amount' => 0.95,
            'composition_snapshot' => [
                'lines' => [
                    ['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné'],
                ],
                'extras' => [],
                // Les 3 variantes de formule scellent le MÊME addon_name — seul `role` diffère.
                'addons' => [
                    ['role' => $role, 'addon_name' => 'Menu (Frites + Boisson)', 'line_total' => $addonTotal, 'unit_price' => $addonTotal, 'quantity' => 1],
                ],
            ],
        ]);
        $oi->name = 'Cayenne';

        return $this->wrapOrder($oi);
    }

    /** Produit STANDALONE (« Frites Seules », item 2) — AUCUN addon menu_*. */
    private function makeStandaloneFritesOrder(): Order
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1,
            'total_price' => 3.00,
            'tax_rate' => 10,
            'tax_name' => 'TVA',
            'tax_type' => 1,
            'tax_amount' => 0.27,
            'composition_snapshot' => [
                'lines' => [],
                'extras' => [],
                'addons' => [],
            ],
        ]);
        $oi->name = 'Frites Seules';

        return $this->wrapOrder($oi);
    }

    private function wrapOrder(OrderItem $oi): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne',
            'address' => '437 Rue Élie Gruyelle',
            'phone' => '+33600000000',
        ]);

        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-ROLE',
            'queue_number' => 'A0077',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => (float) $oi->total_price,
            'total' => (float) $oi->total_price,
            'pos_payment_method' => 1,
            'pos_received_amount' => (float) $oi->total_price,
            'order_datetime' => '2026-07-23 12:30:00',
            'fiscal_sequence_no' => 3100,
        ]);
        $order->setRelation('branch', $branch);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return $order;
    }

    /** @test */
    public function client_ticket_labels_menu_frites_choice_not_raw_addon_name(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeFormulaOrder('menu_frites', 1.80));

        // Le choix « frites seules » s'affiche « Menu Frites » (fidèle au role).
        $this->assertStringContainsString('Menu Frites', $bytes, 'Un choix « frites seules » doit s\'afficher « Menu Frites » sur le ticket client.');
        // JAMAIS le nom d'addon brut (identique pour les 3 variantes → mensonger pour une frite seule).
        $this->assertStringNotContainsString('Menu (Frites', $bytes, 'Le nom brut « Menu (Frites + Boisson) » ne doit pas fuiter pour une frite seule.');
        // Prix SCELLÉ (line_total proratisé) imprimé tel quel — le libellé ne touche pas le prix.
        $this->assertStringContainsString('1,80', $bytes, 'Le prix scellé de la formule doit rester imprimé.');
    }

    /** @test */
    public function client_ticket_labels_menu_boisson_choice_not_raw_addon_name(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeFormulaOrder('menu_boisson', 1.20));

        $this->assertStringContainsString('Menu Boisson', $bytes, 'Un choix « boisson seule » doit s\'afficher « Menu Boisson ».');
        $this->assertStringNotContainsString('Menu (Frites', $bytes, 'Le nom brut ne doit pas fuiter pour une boisson seule.');
        $this->assertStringContainsString('1,20', $bytes, 'Le prix scellé doit rester imprimé.');
    }

    /** @test */
    public function client_ticket_labels_full_menu_as_frites_plus_boisson(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeFormulaOrder('menu_full', 3.00));

        // Formule complète → libellé menu explicite et lisible.
        $this->assertStringContainsString('Menu Frites + Boisson', $bytes, 'La formule complète doit s\'afficher « Menu Frites + Boisson ».');
        $this->assertStringContainsString('3,00', $bytes, 'Le prix scellé de la formule complète doit rester imprimé.');
    }

    /** @test */
    public function client_ticket_keeps_standalone_product_name_untouched(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeStandaloneFritesOrder());

        // Un produit standalone SANS addon menu_* garde son nom d'article : le fix ne le casse pas.
        $this->assertStringContainsString('Frites Seules', $bytes, 'Le produit standalone « Frites Seules » doit garder son nom.');
        // Aucun libellé de formule ne doit apparaître (il n'y a pas d'addon menu_*).
        $this->assertStringNotContainsString('Menu Frites', $bytes, 'Aucun libellé de formule ne doit apparaître pour un standalone sans addon menu.');
        $this->assertStringNotContainsString('Menu (Frites', $bytes, 'Aucun nom d\'addon menu ne doit apparaître pour un standalone.');
    }
}
