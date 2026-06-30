<?php

namespace Tests\Unit\Hardware;

use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use PHPUnit\Framework\TestCase;

/**
 * [KITCHEN-SYMBOLS 2026-06-28] PHP twin of resources/js/helpers/kdsSymbolic.js.
 * The printed kitchen ticket and the KDS screen MUST produce identical symbolic
 * lines — these cases mirror tests/js/kdsSymbolic.spec.js one-for-one.
 */
class KitchenTicketSymbolicFormatterTest extends TestCase
{
    private KitchenTicketSymbolicFormatter $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new KitchenTicketSymbolicFormatter;
    }

    public function test_sandwich_main_line(): void
    {
        $snap = [
            'lines' => [
                ['attribute_name' => 'Pain', 'variation_name' => 'Galette'],
                ['attribute_name' => 'Viande 1', 'variation_name' => 'Poulet mariné'],
                ['attribute_name' => 'Sauce', 'variation_name' => 'Samouraï'],
            ],
            'extras' => [
                ['extra_name' => 'Salade'],
                ['extra_name' => 'Tomate'],
                ['extra_name' => 'Oignon'],
            ],
        ];
        $this->assertSame('G | SANDWICH | P | STO | SAM', $this->f->mainLine('Sandwich', $snap));
    }

    public function test_tacos_main_line_with_default_support(): void
    {
        $snap = [
            'lines' => [
                ['attribute_name' => 'Viande 1', 'variation_name' => 'Viande Hachée'],
                ['attribute_name' => 'Sauce', 'variation_name' => 'Mayonnaise'],
            ],
        ];
        $this->assertSame('G | TACOS | M | K | MAY', $this->f->mainLine('Tacos M', $snap));
    }

    public function test_two_meats_space_joined(): void
    {
        $snap = [
            'lines' => [
                ['attribute_name' => 'Viande 1', 'variation_name' => 'Viande Hachée'],
                ['attribute_name' => 'Viande 2', 'variation_name' => 'Poulet mariné'],
                ['attribute_name' => 'Sauce', 'variation_name' => 'Curry'],
            ],
        ];
        $this->assertSame('G | TACOS | L | K P | CURY', $this->f->mainLine('Tacos L', $snap));
    }

    public function test_crudites_canonical_order(): void
    {
        $snap = [
            'lines' => [['attribute_name' => 'Sauce', 'variation_name' => 'Blanche']],
            'extras' => [['extra_name' => 'Oignon'], ['extra_name' => 'Salade']],
        ];
        $this->assertSame('SANDWICH | SO | BL', $this->f->mainLine('Sandwich', $snap));
    }

    public function test_meat_not_dropped_when_attribute_name_null(): void
    {
        $snap = [
            'lines' => [
                ['attribute_name' => null, 'variation_name' => 'Poulet mariné'],
                ['attribute_name' => 'Sauce', 'variation_name' => 'Mayonnaise'],
            ],
        ];
        $this->assertSame('G | TACOS | M | P | MAY', $this->f->mainLine('Tacos M', $snap));
    }

    public function test_drink_is_just_the_name(): void
    {
        $this->assertSame('COCA 33CL', $this->f->mainLine('Coca 33cl', []));
    }

    public function test_supplement_lines_exclude_crudites(): void
    {
        $snap = [
            'extras' => [
                ['extra_name' => 'Salade'],
                ['extra_name' => 'Cheddar'],
                ['extra_name' => 'Œuf', 'quantity' => 2],
            ],
        ];
        $this->assertSame(['+ Cheddar', '+ Œuf ×2'], $this->f->supplementLines($snap));
    }

    public function test_clean_instruction_drops_compo_blob_keeps_free_note(): void
    {
        // pos-wizard.js (frozen) writes: line0 = PRODUCT NAME, line1 = compo blob,
        // then free notes. The symbolic Line 1 already carries the compo → drop it.
        $raw = "TACOS L\nViandes : Poulet Sauce : Samouraï\n[Sans oignon]\nBien cuit";
        $this->assertSame("[Sans oignon]\nBien cuit", $this->f->cleanInstruction($raw, 'Tacos L'));
        $this->assertSame('', $this->f->cleanInstruction("SANDWICH\nSauce : Blanche", 'Sandwich'));
    }

    /** Couverture EXHAUSTIVE du vrai menu Le Cayenne : chaque valeur → symbole attendu. */
    public function test_every_real_menu_value_maps_to_expected_symbol(): void
    {
        $meats = [
            'Mexicanos' => 'Mex', 'Cordon Bleu' => 'Cordon', 'Viande Hachée' => 'K',
            'Nuggets' => 'Nug', 'Tenders' => 'Tender', 'Fricadelle' => 'Frec', 'Poulet mariné' => 'P',
        ];
        foreach ($meats as $name => $sym) {
            $this->assertSame($sym, $this->f->meatSymbol($name), "viande $name");
        }
        $sauces = [
            'Mayonnaise' => 'MAY', 'Ketchup' => 'KTP', 'Blanche' => 'BL', 'Hannibal' => 'HAN',
            'Samouraï' => 'SAM', 'Algérienne' => 'ALG', 'Andalouse' => 'AND', 'Curry' => 'CURY',
            'Barbecue' => 'BBQ', 'Harissa' => 'HAR', 'Fromagère maison' => 'FRO', 'Spicy maison' => 'SPI',
        ];
        $seen = [];
        foreach ($sauces as $name => $sym) {
            $this->assertSame($sym, $this->f->sauceSymbol($name), "sauce $name");
            $seen[$sym] = ($seen[$sym] ?? 0) + 1;
        }
        // Les 12 sauces produisent 12 symboles DISTINCTS (pas de collision en cuisine).
        $this->assertCount(12, $seen, 'collision de symboles sauce');
        foreach (['Salade' => 'S', 'Tomate' => 'T', 'Oignon' => 'O'] as $name => $sym) {
            $this->assertSame($sym, $this->f->cruditeSymbol($name), "crudité $name");
        }
        $this->assertSame('S', $this->f->supportSymbol('Pain'));
        $this->assertSame('G', $this->f->supportSymbol('Galette'));
    }

    public function test_paid_supplement_named_like_a_crudite_is_not_folded(): void
    {
        // "Oignons frits" (0,90€ supplement) must NOT collapse into the crudités slot
        // like the free "Oignon" garniture, and must appear as a paid line.
        $snap = [
            'lines' => [['attribute_name' => 'Sauce', 'variation_name' => 'Mayonnaise']],
            'extras' => [
                ['extra_name' => 'Salade', 'unit_price' => 0],
                ['extra_name' => 'Oignon', 'unit_price' => 0],
                ['extra_name' => 'Oignons frits', 'unit_price' => 0.90],
            ],
        ];
        // crudités = S + O (free garnitures only), NOT a 2nd O from "Oignons frits".
        $this->assertSame('SANDWICH | SO | MAY', $this->f->mainLine('Sandwich', $snap));
        $this->assertSame(['+ Oignons frits'], $this->f->supplementLines($snap));
    }

    public function test_frites_sauce_symbol_from_instruction(): void
    {
        // Owner: la sauce frites du menu s'affiche en SYMBOLE, pas en nom.
        $this->assertSame('AND', $this->f->fritesSauceSymbol("** + Menu (Frites + Boisson)\n↳ Sauce frites: Andalouse"));
        $this->assertSame('ALG', $this->f->fritesSauceSymbol('Sauce frites : Algérienne'));
        $this->assertSame('', $this->f->fritesSauceSymbol('Bien cuit svp'));
    }

    public function test_clean_instruction_drops_menu_and_frites_sauce_lines(): void
    {
        // Le menu + la sauce frites sont représentés par la ligne « MENU : SYM »,
        // donc on les retire de l'instruction cuisine (anti double-menu / verbeux).
        $raw = "TACOS L\nViandes : Poulet\n+ Menu (Frites + Boisson) (+2,50€)\n↳ Sauce frites: Andalouse\n[Sans oignon]";
        $this->assertSame('[Sans oignon]', $this->f->cleanInstruction($raw, 'Tacos L'));
    }

    public function test_is_menu_item(): void
    {
        $this->assertTrue($this->f->isMenuItem('Menu (Frites + Boisson)'));
        $this->assertTrue($this->f->isMenuItem('Formule midi'));
        $this->assertFalse($this->f->isMenuItem('Cayenne'));
    }

    public function test_menu_line(): void
    {
        $menu = ['addons' => [['addon_name' => 'Frites Moyennes', 'role' => 'menu_frites']]];
        $this->assertSame('MENU', $this->f->menuLine($menu));

        $frites = ['addons' => [['addon_name' => 'Frites', 'role' => null]]];
        $this->assertSame('F', $this->f->menuLine($frites));

        $this->assertSame('', $this->f->menuLine([]));
    }
}
