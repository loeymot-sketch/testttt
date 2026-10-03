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

    /**
     * [Revue « œil du propriétaire » vague 1 · P1] « lorsqu'il y a une frite » : les frites VENDUES COMME
     * PRODUIT (Petite Frites, Grande Frites, Frites Seules, Bol Frites, Frites Cheddar — noms réels de la
     * table items) sont le cas le plus courant et n'étaient pas encadrées. Le Menu Enfant contient des
     * frites : encadré aussi.
     *
     * @return array<string, array{0:string}>
     */
    public static function fritesProduits(): array
    {
        return [
            'Petite Frites' => ['Petite Frites'],
            'Grande Frites' => ['Grande Frites'],
            'Frites Seules' => ['Frites Seules'],
            'Bol Frites' => ['Bol Frites'],
            'Frites Cheddar' => ['Frites Cheddar'],
            'Menu Enfant' => ['Menu Enfant Chicken Burger'],
        ];
    }

    /** @dataProvider fritesProduits */
    public function test_des_frites_vendues_comme_produit_sont_encadrees(string $produit): void
    {
        $b = $this->ticket($produit, 1);

        // Recherche par octets (pas de regex : GS B 0 contient un octet NUL).
        $debut = strpos($b, EscPosCommandBuilder::invert(true).' ');
        $this->assertNotFalse($debut, "« $produit » doit être encadré en noir");
        $finLigne = strpos($b, "\n", $debut);
        $this->assertNotFalse(strpos(substr($b, $debut, $finLigne - $debut), ' '.EscPosCommandBuilder::invert(false)), "le cadre de « $produit » se referme sur la même ligne");
    }

    public function test_un_sandwich_sans_frites_n_est_pas_encadre(): void
    {
        $b = $this->ticket('Cayenne', 1);

        $this->assertStringNotContainsString(EscPosCommandBuilder::invert(true), $b);
    }

    /**
     * [Revue « œil du propriétaire » vague 1 · P1 R-071] Propriétaire, 02/10 : « les supplément dans le ticket
     * de cuisine […] c'est pas écrit en grand ». Inversés depuis le 02/10 mais restés en double HAUTEUR
     * seulement (moitié de la largeur de la ligne produit) : ils passent en DOUBLE TAILLE, comme le produit.
     */
    public function test_les_supplements_sont_en_double_taille_comme_le_produit(): void
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 9.8, 'tax_rate' => 10, 'tax_name' => 'TVA', 'tax_type' => 1, 'tax_amount' => 0.9,
            'instruction' => '',
            'composition_snapshot' => ['lines' => [], 'extras' => [['extra_name' => 'Cheddar', 'unit_price' => 0.9, 'line_total' => 0.9, 'quantity' => 1]], 'addons' => []],
        ]);
        $oi->name = 'Cayenne';
        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-SUP', 'queue_number' => 'A0045', 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 9.8, 'total' => 9.8, 'pos_payment_method' => 1, 'order_datetime' => '2026-10-03 12:00:00', 'fiscal_sequence_no' => 3005,
        ]);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne', 'address' => 'x', 'phone' => '+33600000000']));
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        $b = app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order);

        $this->assertStringContainsString(EscPosCommandBuilder::doubleSize(true).EscPosCommandBuilder::bold(true).'  '.self::inv(' Cheddar '), $b);
    }

    /** Papier 58 mm (32 col.) : un mot trop long pour la double largeur n'est JAMAIS coupé (régression C4-001). */
    public function test_sur_papier_etroit_un_supplement_long_n_est_jamais_coupe_en_plein_mot(): void
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 11.4, 'tax_rate' => 10, 'tax_name' => 'TVA', 'tax_type' => 1, 'tax_amount' => 1,
            'instruction' => '',
            'composition_snapshot' => ['lines' => [], 'extras' => [['extra_name' => 'Viande supplémentaire', 'unit_price' => 2.5, 'line_total' => 2.5, 'quantity' => 1]], 'addons' => []],
        ]);
        $oi->name = 'Cayenne';
        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-58', 'queue_number' => 'A0046', 'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 11.4, 'total' => 11.4, 'pos_payment_method' => 1, 'order_datetime' => '2026-10-03 12:00:00', 'fiscal_sequence_no' => 3006,
        ]);
        $order->setRelation('branch', (new Branch)->forceFill(['name' => 'Le Cayenne', 'address' => 'x', 'phone' => '+33600000000']));
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        $b = app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order, ['width_chars' => 32]);

        // Le ticket sort encodé CP858 (« é » = 0x82) : la chaîne attendue est encodée pareil.
        $this->assertStringContainsString(
            self::inv((string) iconv('UTF-8', 'CP858', ' Viande supplémentaire ')),
            $b,
            'tout le libellé tient sur UNE ligne en double hauteur, mot entier'
        );
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
