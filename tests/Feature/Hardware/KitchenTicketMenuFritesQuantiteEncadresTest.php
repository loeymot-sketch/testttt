<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\EscPosCommandBuilder;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [GOAL REMARQUES 2026-10-03 · T-1.5 R-053 + T-1.6 R-054] Ticket cuisine, propriétaire 07/09 :
 *  - « lorsqu'il y a une frite, je veux que ça soit encadré soit menu soit frites ça doit être
 *    encadré en noir » → les lignes MENU / FRITES en lecture inversée (GS B), comme les suppléments ;
 *  - « Froid deux […] en gras fois deux […] ou bien les mettre avec une arrière-plan en noir » →
 *    le préfixe de quantité (> 1) en lecture inversée.
 * La BOISSON seule n'est pas une frite : elle reste en gras simple.
 */
class KitchenTicketMenuFritesQuantiteEncadresTest extends TestCase
{
    private function ticket(string $name, int $qty, array $addons = [], string $instruction = ''): string
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => $qty, 'total_price' => 8.9, 'tax_rate' => 10, 'tax_name' => 'TVA', 'tax_type' => 1, 'tax_amount' => 0.8,
            'instruction' => $instruction,
            'composition_snapshot' => [
                'lines' => [['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Algérienne']],
                'extras' => [],
                'addons' => $addons,
            ],
        ]);
        $oi->name = $name;
        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-ENC', 'queue_number' => 'A0044', 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 8.9, 'total' => 8.9, 'pos_payment_method' => 1, 'order_datetime' => '2026-10-03 12:00:00', 'fiscal_sequence_no' => 3004,
        ]);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne', 'address' => 'x', 'phone' => '+33600000000']));
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order);
    }

    private static function inv(string $texte): string
    {
        return EscPosCommandBuilder::invert(true).$texte.EscPosCommandBuilder::invert(false);
    }

    public function test_le_badge_menu_est_encadre_en_noir(): void
    {
        $b = $this->ticket('Cayenne', 1, [['role' => 'menu_full', 'name' => 'Menu (Frites + Boisson)', 'quantity' => 1]], "CAYENNE\n↳ Sauce frites: Mayonnaise");

        $this->assertStringContainsString(self::inv(' MENU : MAY '), $b);
    }

    public function test_le_badge_frites_est_encadre_en_noir(): void
    {
        $b = $this->ticket('Cayenne', 1, [['role' => 'menu_frites', 'name' => 'Frites', 'quantity' => 1]], 'CAYENNE');

        $this->assertStringContainsString(EscPosCommandBuilder::invert(true).' FRITES', $b);
    }

    public function test_une_boisson_seule_n_est_pas_encadree(): void
    {
        $b = $this->ticket('Cayenne', 1, [['role' => 'menu_boisson', 'name' => 'Coca-Cola 33cl', 'quantity' => 1]], 'CAYENNE');

        $this->assertStringContainsString('BOISSON', $b);
        $this->assertStringNotContainsString(EscPosCommandBuilder::invert(true).' BOISSON', $b);
    }

    public function test_une_formule_commandee_seule_est_encadree(): void
    {
        $b = $this->ticket('Menu (Frites + Boisson)', 1, [], 'Sauce frites: Mayonnaise');

        $this->assertStringContainsString(self::inv(' MENU : MAY '), $b);
    }

    public function test_la_quantite_multiple_est_sur_fond_noir(): void
    {
        $b = $this->ticket('Tacos M', 2);

        $this->assertStringContainsString(self::inv('2 x ').'Tacos', $b);
        $this->assertSame(substr_count($b, EscPosCommandBuilder::invert(true)), substr_count($b, EscPosCommandBuilder::invert(false)), "l'inversion doit toujours être refermée");
    }

    public function test_une_quantite_de_un_n_a_ni_prefixe_ni_fond_noir(): void
    {
        $b = $this->ticket('Tacos M', 1);

        $this->assertStringNotContainsString(EscPosCommandBuilder::invert(true), $b);
    }
}
