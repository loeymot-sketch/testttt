<?php

namespace Tests\Feature\Hardware;

use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use Tests\TestCase;

/**
 * [OWNER 2026-08-10 · « les sauces, si il a pris plusieurs, faut les afficher au bon endroit —
 * si pour les frites ou pour sandwich »]
 *
 * Les wizards de saisie sont FROZEN : ils facturent la sauce en plus comme un extra GÉNÉRIQUE et
 * SANS NOM (« Sauce supplémentaire »). L'identité de la sauce ne survit que dans le texte libre,
 * sur DEUX canaux distincts :
 *   · « Sauces en plus : … »   → sauces du PRODUIT  → repliées dans la ligne 1 symbolique ;
 *   · « Sauce frites : A, B »  → sauces des FRITES  → la 1ʳᵉ est offerte, les suivantes sont
 *                                 payantes, et elles s'affichent sur le badge (« MENU : KTP MAY »).
 *
 * Seul le premier canal était compté. Conséquence mesurée sur des commandes RÉELLES (#5835,
 * #5810, #5755) : la sauce payante des FRITES ressortait une seconde fois, anonyme, en supplément
 * du sandwich — une sauce fantôme de plus, dont le cuisinier ne pouvait pas savoir où elle allait.
 *
 * La règle scellée ici : chaque sauce payée apparaît UNE fois, à sa place ; et une sauce facturée
 * que rien n'explique reste VISIBLE — jamais masquée en silence.
 */
class KitchenTicketSaucesPlacementTest extends TestCase
{
    private KitchenTicketSymbolicFormatter $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new KitchenTicketSymbolicFormatter;
    }

    /** @param array<int,array<string,mixed>> $extras */
    private function snap(array $lines = [], array $extras = [], array $addons = []): array
    {
        return ['lines' => $lines, 'extras' => $extras, 'addons' => $addons];
    }

    private function sauceExtra(int $q = 1): array
    {
        return ['extra_name' => 'Sauce supplémentaire', 'quantity' => $q, 'unit_price' => 0.5, 'line_total' => 0.5 * $q];
    }

    /**
     * @test
     *
     * Forme EXACTE de la commande réelle #5835 (borne) : 1 sauce sandwich offerte (Fromagère)
     * + 2 sauces frites (Ketchup offerte, Mayonnaise payée).
     */
    public function la_sauce_payante_des_frites_ne_ressort_pas_en_supplement_du_sandwich(): void
    {
        $snap = $this->snap(
            [['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Fromagère maison']],
            [['extra_name' => 'Salade', 'quantity' => 1, 'unit_price' => 0], $this->sauceExtra()],
            [['role' => 'menu_full', 'addon_name' => 'Menu (Frites + Boisson)', 'quantity' => 1]]
        );
        $instr = 'Boisson menu: Coca-Cola 33cl · Sauce frites : Ketchup, Mayonnaise';

        // La sauce du SANDWICH est sur la ligne 1, les sauces des FRITES sur le badge.
        $this->assertStringContainsString('FRO', $this->f->mainLine('Cayenne', $snap, $instr));
        $this->assertSame('MENU : KTP MAY', $this->f->menuBadge($snap, 'Cayenne', $instr));

        // …et RIEN en plus : la sauce payante est celle des frites, déjà annoncée sur le badge.
        $this->assertSame([], $this->f->supplementLines($snap, $instr),
            'Sauce fantôme : la sauce payante des frites est annoncée deux fois, dont une sans nom.');
    }

    /**
     * @test
     *
     * Forme EXACTE de la commande réelle #5810 : « Grande Frites » avec TROIS sauces
     * (1 offerte + 2 payées).
     */
    public function trois_sauces_de_frites_tiennent_dans_le_badge_et_nulle_part_ailleurs(): void
    {
        $snap = $this->snap([], [$this->sauceExtra(2)]);
        $instr = 'Sauce frites : Mayonnaise, Ketchup, Samouraï';

        $this->assertSame('FRITES : MAY KTP SAM', $this->f->menuBadge($snap, 'Grande Frites', $instr));
        $this->assertSame([], $this->f->supplementLines($snap, $instr));
    }

    /** @test */
    public function la_sauce_en_plus_du_SANDWICH_reste_repliee_dans_la_ligne_1(): void
    {
        $snap = $this->snap(
            [['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Mayonnaise']],
            [$this->sauceExtra()]
        );
        $instr = 'Sauces en plus : Andalouse';

        $ligne = $this->f->mainLine('Cayenne', $snap, $instr);
        $this->assertStringContainsString('MAY', $ligne);
        $this->assertStringContainsString('AND', $ligne);
        $this->assertSame([], $this->f->supplementLines($snap, $instr));
    }

    /** @test */
    public function les_deux_canaux_a_la_fois_sont_comptes_ensemble(): void
    {
        // 1 sauce sandwich en plus + 1 sauce frites en plus = 2 unités payées, toutes deux
        // déjà annoncées ailleurs.
        $snap = $this->snap(
            [['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Mayonnaise']],
            [$this->sauceExtra(2)]
        );
        $instr = "Sauces en plus : Andalouse\nSauce frites : Ketchup, Samouraï";

        $this->assertSame([], $this->f->supplementLines($snap, $instr));
    }

    /**
     * @test
     *
     * LA GARDE QUI COMPTE : une sauce FACTURÉE que rien n'explique ne disparaît JAMAIS. Mieux
     * vaut une ligne anonyme que le cuisinier interroge, qu'une sauce vendue et jamais servie.
     */
    public function une_sauce_payee_que_rien_n_explique_reste_visible(): void
    {
        // Une seule sauce frites nommée = la sauce OFFERTE. Rien n'explique l'extra payé.
        $snap = $this->snap([], [$this->sauceExtra()]);
        $instr = 'Sauce frites : Ketchup';

        $this->assertSame(['+ Sauce supplémentaire'], $this->f->supplementLines($snap, $instr));
    }

    /** @test */
    public function le_surplus_non_explique_est_affiche_avec_son_compte(): void
    {
        // 3 sauces payées, une seule expliquée (la 2ᵉ sauce frites) → 2 restent visibles.
        $snap = $this->snap([], [$this->sauceExtra(3)]);
        $instr = 'Sauce frites : Ketchup, Mayonnaise';

        $this->assertSame(['+ Sauce supplémentaire ×2'], $this->f->supplementLines($snap, $instr));
    }

    /**
     * @test
     *
     * Les frites d'un MENU ENFANT viennent de sa RECETTE (MeatPortionCalculator::RECETTES_FIXES,
     * F:1), pas d'un addon : le bandeau de cuisson les compte, mais AUCUN badge ne les portait —
     * donc la sauce choisie par le client disparaissait entièrement, le nettoyeur d'instruction
     * ayant retiré la ligne « Sauce frites : … » censée être rendue par ce badge.
     */
    public function la_sauce_des_frites_d_un_menu_enfant_est_visible(): void
    {
        $snap = $this->snap();
        $instr = 'Sauce frites : Ketchup';

        $this->assertSame('FRITES : KTP', $this->f->menuBadge($snap, 'Menu Enfant Chicken Burger', $instr));
    }

    /** @test */
    public function sans_sauce_frites_aucun_badge_n_apparait(): void
    {
        $this->assertSame('', $this->f->menuBadge($this->snap(), 'Cayenne', 'note libre du client'));
        $this->assertSame('', $this->f->menuBadge($this->snap(), 'Menu Enfant Nuggets', null));
    }

    /**
     * @test
     *
     * Commande réelle #5896 : le retrait des segments de composition laissait « · . » imprimé
     * comme note client — un résidu qui ressemble à un défaut d'impression.
     */
    public function le_retrait_des_segments_de_composition_ne_laisse_aucun_residu(): void
    {
        $instr = 'Viandes en plus : Nuggets, Poulet mariné. · Sauces en plus : Algérienne.';

        $this->assertSame('', $this->f->cleanInstruction($instr, 'Galette Normale'));
    }

    /** @test */
    public function une_vraie_note_client_survit_toujours(): void
    {
        $instr = "Viandes en plus : Nuggets. · Sauces en plus : Algérienne.\n[ALLERGIE ARACHIDE — sans cacahuète]";

        $this->assertStringContainsString('ALLERGIE ARACHIDE', $this->f->cleanInstruction($instr, 'Tacos L'));
    }
}
