<?php

namespace Tests\Unit\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\EscPosCommandBuilder;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [GOAL REMARQUES 2026-10-03 · T-1.2 R-072] Jumeau ticket de tests/js/kdsDieseTousSupplements.spec.js.
 *
 * Propriétaire, 02/10 puis 03/10 : « mettre un dièse si il y a un produit avec des supplément ».
 * Le « # » n'était imprimé que si une ligne supplément sortait. Une sauce EN PLUS payée est
 * volontairement repliée dans la ligne produit, et la 2ᵉ sauce frites sur le badge : pas de ligne
 * supplément, donc pas de « # », alors que le produit porte un supplément payé.
 */
class KitchenTicketDieseTousSupplementsTest extends TestCase
{
    private const SAUCE_EN_PLUS = ['extra_name' => 'Sauce supplémentaire', 'unit_price' => 0.5, 'line_total' => 0.5, 'quantity' => 1];

    private function item(string $name, array $extras, string $instruction): OrderItem
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
        $oi->name = $name;

        return $oi;
    }

    private function ticket(OrderItem ...$items): string
    {
        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-DIESE', 'queue_number' => 'A0043', 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.9, 'total' => 8.9, 'pos_payment_method' => 1, 'order_datetime' => '2026-10-03 12:00:00', 'fiscal_sequence_no' => 3002,
        ]);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne', 'address' => 'x', 'phone' => '+33600000000']));
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect($items));

        return app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order);
    }

    private function assertDiese(string $b, string $ctx): void
    {
        $this->assertStringContainsString(EscPosCommandBuilder::bold(true).'  # ', $b, "$ctx : la ligne produit doit commencer par « # »");
    }

    public function test_sauce_en_plus_repliee_dans_la_ligne_produit(): void
    {
        $b = $this->ticket($this->item('Tacos M', [self::SAUCE_EN_PLUS], "TACOS M\nPain Sauce : Algérienne, Samouraï"));

        $this->assertStringNotContainsString('Sauce supplémentaire', $b, 'la sauce reste repliée dans la ligne produit');
        $this->assertStringContainsString('SAM', $b);
        $this->assertDiese($b, 'sauce en plus');
    }

    public function test_deuxieme_sauce_frites_payee_sur_le_badge(): void
    {
        $b = $this->ticket($this->item('Cayenne', [self::SAUCE_EN_PLUS],
            "CAYENNE\nPain Sauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)\n↳ Sauce frites: Mayonnaise, Ketchup"));

        $this->assertDiese($b, '2ᵉ sauce frites');
    }

    public function test_extra_offert_nomme_comme_une_crudite(): void
    {
        $b = $this->ticket($this->item('Tacos M', [['extra_name' => 'Oignons frits', 'unit_price' => 0, 'line_total' => 0, 'quantity' => 1, 'offered' => true, 'catalog_unit_price' => 0.9]], ''));

        $this->assertStringContainsString('Oignons frits', $b);
        $this->assertDiese($b, 'extra offert');
    }

    public function test_option_de_formule_heritee_de_la_ligne_formule_repliee(): void
    {
        $parent = $this->item('Cayenne', [], "CAYENNE\nPain Sauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)\n↳ Sauce frites: Mayonnaise");
        $formule = $this->item('Menu (Frites + Boisson)', [['extra_name' => 'Grande Portion', 'unit_price' => 1, 'line_total' => 1, 'quantity' => 1]], 'Sauce frites: Mayonnaise');

        $b = $this->ticket($parent, $formule);

        $this->assertStringContainsString('Grande Portion', $b);
        $this->assertDiese($b, 'option de formule');
    }

    /** Instruction RÉELLE de la caisse (pos-wizard.js buildTicketInstruction : « ↳ Grande Portion (+1.00€) »). */
    public function test_option_de_formule_forme_reelle_imprimee_une_seule_fois(): void
    {
        $parent = $this->item('Cayenne', [], "CAYENNE\nPain Sauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)\n↳ Sauce frites: Mayonnaise\n↳ Grande Portion (+1.00€)");
        $formule = $this->item('Menu (Frites + Boisson)', [['extra_name' => 'Grande Portion', 'unit_price' => 1, 'line_total' => 1, 'quantity' => 1]], "Sauce frites: Mayonnaise\n↳ Grande Portion (+1.00€)");

        $b = $this->ticket($parent, $formule);

        $this->assertSame(1, substr_count($b, 'Grande Portion'), 'une seule mention de « Grande Portion » sur le ticket cuisine : '.addcslashes($b, "\0..\37"));
        $this->assertDiese($b, 'option de formule, forme réelle');
    }

    public function test_contre_epreuve_garniture_gratuite_seule(): void
    {
        $b = $this->ticket($this->item('Tacos M', [['extra_name' => 'Salade', 'unit_price' => 0, 'line_total' => 0, 'quantity' => 1]], ''));

        $this->assertStringNotContainsString('# ', $b);
    }
}
