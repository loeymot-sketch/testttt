<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Tests\TestCase;

/**
 * [MEGA-BORNE Wave 1 2026-07-22 owner] Refonte du format symbolique cuisine (ticket + KDS) :
 *
 *  CHANGE 1 — Sauce écrite devant SA catégorie (produit vs menu) :
 *    Les sauces PRODUIT (1ère incluse + en plus) s'écrivent ENSEMBLE dans le slot Sauce(s)
 *    de la LIGNE 1 (« FRO MAY ») ; la sauce en plus n'est PLUS une ligne « + Sauce
 *    supplémentaire ». La sauce FRITES du menu reste, elle, en LIGNE 2 (« MENU : SYM »).
 *
 *  CHANGE 2 — Tacos SANS taille, viandes montrées directement :
 *    Un TACOS ne montre PAS la taille — le nombre de viandes (« K P ») porte l'info.
 *
 * Jumeau STRICT du JS kdsSymbolic.js (mêmes entrées/sorties que
 * tests/js/kdsSymbolic.spec.js « [MEGA-BORNE] »). Affichage seul : prix/NF525 inchangés
 * (le ticket CLIENT fiscal garde le NOM + le prix de la sauce — cf. MultiSauceTicketNamesTest).
 */
class KitchenTicketTacosSauceTest extends TestCase
{
    private KitchenTicketSymbolicFormatter $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new KitchenTicketSymbolicFormatter;
    }

    // ── CHANGE 1 : sauces produit ensemble sur la ligne 1 ─────────────────────

    /** @test */
    public function product_sauces_are_written_together_on_line_1(): void
    {
        // Cayenne : 1ère sauce INCLUSE (variation Fromagère → FRO) + 1 sauce EN PLUS
        // (Mayonnaise, véhiculée par l'extra générique + récupérée depuis l'instruction) → « FRO MAY ».
        $snap = [
            'lines' => [
                ['attribute_name' => 'Type de Pain', 'variation_name' => 'Pain'],
                ['attribute_name' => 'Sauce', 'variation_name' => 'Fromagère'],
                ['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné'],
            ],
            'extras' => [
                ['extra_name' => 'Sauce supplémentaire', 'unit_price' => 0.50, 'quantity' => 1],
            ],
        ];
        $instruction = "CAYENNE\nViandes : Poulet mariné Sauce : Fromagère, Mayonnaise";

        $this->assertSame('S | CAY | P | FRO MAY', $this->f->mainLine('Cayenne', $snap, $instruction));
    }

    /** @test */
    public function extra_sauce_no_longer_appears_as_a_supplement_line(): void
    {
        $snap = [
            'lines' => [['attribute_name' => 'Sauce', 'variation_name' => 'Fromagère']],
            'extras' => [
                ['extra_name' => 'Cheddar', 'unit_price' => 1.00, 'quantity' => 1],
                ['extra_name' => 'Sauce supplémentaire', 'unit_price' => 0.50, 'quantity' => 1],
            ],
        ];
        // La sauce remonte en ligne 1 → seule reste la ligne d'un VRAI supplément (Cheddar).
        $this->assertSame(['+ Cheddar'], $this->f->supplementLines($snap, 'Sauce : Fromagère, Mayonnaise'));
    }

    /** @test */
    public function retro_compatible_generic_sauce_line_when_name_unrecoverable(): void
    {
        // Sans instruction parsable, impossible de remonter le nom en ligne 1 → on GARDE le
        // libellé générique en supplément (ne pas perdre l'info que le client a payé une sauce).
        $snap = ['extras' => [['extra_name' => 'Sauce supplémentaire', 'unit_price' => 0.50, 'quantity' => 1]]];
        $this->assertSame(['+ Sauce supplémentaire'], $this->f->supplementLines($snap, 'TACOS M'));
        $this->assertSame(['+ Sauce supplémentaire'], $this->f->supplementLines($snap, null));
    }

    /** @test */
    public function frites_sauce_stays_on_line_2_not_folded_into_product_sauces(): void
    {
        // La sauce FRITES du menu est un canal distinct (ligne 2 « MENU : SYM ») — extraSauceNames
        // ne la capte JAMAIS, donc elle ne remonte pas dans le slot Sauce(s) produit de la ligne 1.
        $snap = [
            'lines' => [['attribute_name' => 'Sauce', 'variation_name' => 'Fromagère']],
            'addons' => [['role' => 'menu_full', 'addon_name' => 'Menu (Frites + Boisson)']],
        ];
        $instruction = "Pain : Pain\nSauce frites : Algérienne";
        // Ligne 1 : seule la sauce produit (FRO) — PAS la sauce frites (ALG).
        $this->assertSame('CAY | FRO', $this->f->mainLine('Cayenne', $snap, $instruction));
        // La sauce frites reste en ligne 2 (« MENU : ALG »).
        $this->assertSame('ALG', $this->f->fritesSauceSymbol($instruction));
        $this->assertSame([], $this->f->extraSauceNames($instruction));
    }

    // ── CHANGE 2 : tacos sans taille, viandes directes ────────────────────────

    /** @test */
    public function tacos_line_drops_the_size_and_shows_the_meats(): void
    {
        $snap = [
            'lines' => [
                ['attribute_name' => 'Viande 1', 'variation_name' => 'Viande Hachée'],
                ['attribute_name' => 'Viande 2', 'variation_name' => 'Poulet mariné'],
                ['attribute_name' => 'Sauce', 'variation_name' => 'Curry'],
            ],
        ];
        // Avant : « G | TAC | L | K P | CURY ». Après : plus de « L » (les 2 viandes portent l'info).
        $this->assertSame('G | TAC | K P | CURY', $this->f->mainLine('Tacos L', $snap));
        $this->assertSame('G | TAC | K P | CURY', $this->f->mainLine('Tacos XL', $snap));
    }

    /** @test */
    public function tacos_drops_size_even_from_a_size_variation(): void
    {
        // Taille portée par une VARIATION (pas le nom) → également ignorée pour un tacos.
        $snap = [
            'lines' => [
                ['attribute_name' => 'Taille', 'variation_name' => 'XL'],
                ['attribute_name' => 'Viande 1', 'variation_name' => 'Mexicanos'],
                ['attribute_name' => 'Sauce', 'variation_name' => 'Mayonnaise'],
            ],
        ];
        $this->assertSame('G | TAC | Mex | MAY', $this->f->mainLine('Tacos', $snap));
    }

    /** @test */
    public function non_tacos_products_keep_their_size(): void
    {
        // Régression-guard : un produit NON-tacos avec taille garde son format (owner).
        $snap = ['lines' => [['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné']]];
        $this->assertSame('BUR | M | P', $this->f->mainLine('Burger M', $snap));
    }

    // ── Ticket CUISINE (ESC/POS) bout-en-bout : les deux changements réunis ────

    /** @test */
    public function kitchen_ticket_tacos_two_meats_two_sauces_no_size_no_supplement(): void
    {
        $order = $this->makeTacosOrder(
            'Algérienne',
            ['Viande Hachée', 'Poulet mariné'],
            "TACOS\nViandes : Viande Hachée, Poulet mariné Sauce : Algérienne, Andalouse",
        );
        $kitchen = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($order);

        // Produit + viandes (K P) présents ; les 2 sauces en symbole (ALG, AND) ; pas de supplément sauce.
        $this->assertStringContainsString('TAC', $kitchen);
        $this->assertStringContainsString('ALG', $kitchen, '1ère sauce (symbole) en ligne 1');
        $this->assertStringContainsString('AND', $kitchen, 'sauce en plus (symbole) en ligne 1');
        $this->assertStringNotContainsString('Sauce suppl', $kitchen, 'la sauce ne doit plus être un supplément');
        // Aucune taille « M/L/XL » isolée en cuisine (le nombre de viandes porte l'info).
        $this->assertStringNotContainsString('| L |', $kitchen);
        $this->assertStringNotContainsString('| XL |', $kitchen);
    }

    private function makeTacosOrder(string $firstSauce, array $meats, string $instruction): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne', 'address' => '437 Rue Élie Gruyelle', 'phone' => '+33600000000',
        ]);

        $lines = [['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => $firstSauce]];
        foreach ($meats as $i => $m) {
            $lines[] = ['attribute_name' => 'Viande '.($i + 1), 'variation_name' => $m];
        }

        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 8.90, 'tax_rate' => 10, 'tax_name' => 'TVA',
            'tax_type' => 1, 'tax_amount' => 0.81, 'instruction' => $instruction,
            'composition_snapshot' => [
                'lines' => $lines,
                'extras' => [
                    ['extra_name' => 'Salade', 'line_total' => 0, 'quantity' => 1],
                    ['extra_name' => 'Sauce supplémentaire', 'line_total' => 0.50, 'unit_price' => 0.50, 'quantity' => 1],
                ],
                'addons' => [],
            ],
        ]);
        // Nom AVEC taille (« Tacos L ») pour prouver bout-en-bout que la taille est retirée du ticket.
        $oi->name = 'Tacos L';

        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-TS', 'queue_number' => 'A0044',
            'order_type' => \App\Enums\OrderType::TAKEAWAY, 'subtotal' => 8.90, 'total' => 8.90,
            'pos_payment_method' => 1, 'pos_received_amount' => 8.90,
            'order_datetime' => '2026-07-22 12:00:00', 'fiscal_sequence_no' => 3003,
        ]);
        $order->setRelation('branch', $branch);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return $order;
    }
}
