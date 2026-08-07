<?php

namespace Tests\Feature\Kitchen;

use App\Services\Kitchen\MeatPortionCalculator;
use Tests\TestCase;

/**
 * [GOAL CUISSON 2026-08-06] Sentinelle du moteur de portions de viande.
 *
 * Ce test APPELLE le moteur : il ne lit pas le source. La campagne précédente a montré qu'une
 * sentinelle en expression régulière sur le code reste verte alors que le correctif a été
 * intégralement annulé — 21 assertions sur 35 ne prouvaient rien. Ici, chaque cas décrit une
 * commande réelle et vérifie la ligne que le cuisinier lira.
 *
 * Les fixtures utilisent la clé `lines` du composition_snapshot, qui est celle réellement
 * produite en production : mon premier jet lisait `variations` et ma propre fixture, écrite dans
 * la même erreur, rendait le défaut invisible.
 */
class MeatPortionCalculatorTest extends TestCase
{
    private MeatPortionCalculator $moteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moteur = new MeatPortionCalculator();
    }

    /** Fabrique un composition_snapshot à la forme canonique de production. */
    private function snap(array $viandes, array $extras = []): array
    {
        $lines = [];
        foreach (array_values($viandes) as $i => $nom) {
            $lines[] = ['attribute_name' => 'Viande '.($i + 1), 'variation_name' => $nom];
        }
        // Une ligne non-viande DOIT être ignorée : sinon la sauce se retrouverait à la plancha.
        $lines[] = ['attribute_name' => 'Sauce 1', 'variation_name' => 'Algérienne'];

        return ['lines' => $lines, 'extras' => $extras];
    }

    private function ligne(string $item, array $viandes, int $qte = 1, array $extras = [], ?string $instruction = null): string
    {
        $r = $this->moteur->forLine($item, $this->snap($viandes, $extras), $qte, $instruction);

        return $this->moteur->rendu($r['pieces'], $r['inconnu'] ? $qte : 0);
    }

    /**
     * RÈGLE 1 — un produit à UNE viande reçoit la PORTION COMPLÈTE, soit 2 pièces.
     * Owner : « pour le tacos si ont mis la viande hachée, on va mettre deux viande hachée
     * deux pièces », « si il est un Cayenne en viande hachée, on va mettre 2 ».
     */
    public function test_un_produit_a_une_seule_viande_recoit_la_portion_complete(): void
    {
        $this->assertSame('2K', $this->ligne('Tacos M', ['Viande Hachée']));
        $this->assertSame('2K', $this->ligne('Cayenne', ['Viande Hachée']));
        // [owner 2026-08-07] Le POULET se compte en PORTIONS : une portion complète = 1 (200 g),
        // là où la viande hachée se compte en STEAKS (2 par portion).
        $this->assertSame('1P', $this->ligne('Galette Normale', ['Poulet mariné']));
        $this->assertSame('2Cordon', $this->ligne('Bol Riz', ['Cordon Bleu']));
    }

    /**
     * RÈGLE 2 — un produit à DEUX viandes reçoit UNE pièce de chacune (deux demi-portions).
     * Owner : « si il est méga les mixtes entre deux viandes, alors ça va être une moitié
     * portion de poulet et une moitié portion de viande hachée ».
     */
    public function test_un_produit_a_deux_viandes_recoit_une_demi_portion_de_chacune(): void
    {
        $this->assertSame('1K 0,5P', $this->ligne('Méga', ['Viande Hachée', 'Poulet mariné']), 'Mixte : 1 steak (demi-portion de hachée) + une DEMI-portion de poulet (100 g).');
        $this->assertSame('1Cordon 1Mex', $this->ligne('Tacos L', ['Mexicanos', 'Cordon Bleu']));
    }

    /**
     * RÈGLE 3 — la valeur d'une portion complète DÉPEND de la viande (owner 2026-08-07) :
     * 2 steaks, 1 portion de poulet (200 g), 4 nuggets, 3 tenders, 2 pièces pour les autres.
     * Les pièces entières ne se coupent pas en deux : seule la portion de poulet est décimale.
     *
     * @dataProvider portionsParViande
     */
    public function test_la_portion_complete_depend_de_la_viande(string $cas, array $viandes, string $attendu): void
    {
        $this->assertSame($attendu, $this->ligne('Tacos', $viandes), $cas);
    }

    public static function portionsParViande(): array
    {
        return [
            'nuggets seuls = 4 nuggets'          => ['1 emplacement', ['Nuggets'], '4Nug'],
            'tenders seuls = 3 tenders'          => ['1 emplacement', ['Tenders'], '3Tender'],
            'cordon seul = 2 pièces'             => ['1 emplacement', ['Cordon Bleu'], '2Cordon'],
            'deux fois cordon = 2Cordon (owner)' => ['2 emplacements', ['Cordon Bleu', 'Cordon Bleu'], '2Cordon'],
            'cordon + poulet (owner)'            => ['2 emplacements', ['Cordon Bleu', 'Poulet mariné'], '0,5P 1Cordon'],
            'nuggets + poulet'                   => ['2 emplacements', ['Nuggets', 'Poulet mariné'], '0,5P 2Nug'],
        ];
    }

    /** Deux fois la MÊME viande sur un produit à deux emplacements se recompose en portion pleine. */
    public function test_deux_fois_la_meme_viande_se_recompose_en_portion_pleine(): void
    {
        $this->assertSame('2K', $this->ligne('Terminator', ['Viande Hachée', 'Viande Hachée']));
    }

    /** Un choix « Mixte (hachée + poulet) » occupe UN emplacement, partagé entre ses deux viandes. */
    public function test_le_choix_mixte_partage_son_emplacement_entre_ses_deux_viandes(): void
    {
        $this->assertSame('1K 0,5P', $this->ligne('Cayenne', ['Mixte (hachée + poulet)']));
    }

    /** La quantité de la ligne multiplie les pièces : 3 tacos hachée = 6 steaks à cuire. */
    public function test_la_quantite_de_ligne_multiplie_les_pieces(): void
    {
        $this->assertSame('6K', $this->ligne('Tacos M', ['Viande Hachée'], 3));
    }

    /**
     * Le supplément viande vaut une PORTION COMPLÈTE, et son NOM est récupéré depuis
     * l'instruction — les wizards sont frozen et facturent un extra générique et sans nom.
     */
    public function test_le_supplement_viande_vaut_une_portion_complete_et_est_nomme(): void
    {
        $extra = [['extra_name' => 'Viande supplémentaire', 'quantity' => 1]];

        $this->assertSame(
            '2K 1P',
            $this->ligne('Cayenne', ['Poulet mariné'], 1, $extra, 'Viandes en plus : Viande Hachée'),
            'Le Cayenne seul en poulet = 1 portion ; le supplément hachée apporte une portion complète, soit 2K.'
        );
    }

    /**
     * Sans nom récupérable, le supplément doit RESTER VISIBLE sous « ? » : le cuisinier doit
     * savoir qu'il a une viande de plus à cuire même quand le ticket ne dit pas laquelle.
     * L'escamoter serait pire que l'afficher imparfaitement.
     */
    public function test_un_supplement_non_nommable_reste_visible_plutot_que_disparaitre(): void
    {
        $rendu = $this->ligne('Cayenne', ['Poulet mariné'], 1, [['extra_name' => 'Viande supplémentaire', 'quantity' => 1]]);

        $this->assertStringContainsString('?', $rendu, 'Un supplément non nommé ne doit jamais disparaître silencieusement du bandeau de cuisson.');
        $this->assertStringContainsString('1P', $rendu);
    }

    /**
     * LES RECETTES FIXES — données owner du 2026-08-06, chacune confirmée contre la colonne
     * `description` de la table items (l'owner avait demandé vérification). Le jambon de dinde
     * et le cheddar sont volontairement absents : ils ne passent pas à la plancha.
     *
     * @dataProvider recettesFixes
     */
    public function test_les_recettes_fixes_suivent_la_composition_documentee(string $item, string $attendu): void
    {
        $r = $this->moteur->forLine($item, $this->snap([]), 1);

        $this->assertFalse($r['inconnu'], "« {$item} » a une recette documentée : il ne doit plus être signalé inconnu.");
        $this->assertSame($attendu, $this->moteur->rendu($r['pieces'], 0));
    }

    public static function recettesFixes(): array
    {
        return [
            'Cheese Burger — « Steak »'                        => ['Cheese Burger', '1K'],
            'Double Cheese — « 2 steaks »'                     => ['Double Cheese', '2K'],
            'Grill Burger — « 2 steaks, jambon de dinde »'     => ['Grill Burger', '2K'],
            'Big Burger — « 3 steaks, 2 jambons de dinde »'    => ['Big Burger', '3K'],
            'Fish Burger — « Poisson pané »'                   => ['Fish Burger', '1Poi'],
            'Chicken Burger'                                   => ['Chicken Burger', '1Chick'],
            'Suprême — « Steak haché, cordon bleu »'           => ['Suprême', '1K 1Cordon'],
            'Menu Enfant Nuggets — « 6 nuggets, frites »'      => ['Menu Enfant Nuggets', '6Nug 1F'],
            'Menu Enfant Chicken — « Chicken burger, frites »' => ['Menu Enfant Chicken Burger', '1Chick 1F'],
        ];
    }

    /**
     * L'ORDRE des motifs est porteur de sens : « Double Cheese » ne doit pas être avalé par
     * « Cheese Burger », ni « Menu Enfant Chicken Burger » par « Chicken Burger ». Une
     * inversion donnerait un steak au lieu de deux — silencieusement.
     */
    public function test_les_recettes_qui_se_chevauchent_ne_se_volent_pas(): void
    {
        $this->assertSame('2K', $this->moteur->rendu($this->moteur->forLine('Double Cheese', $this->snap([]))['pieces']));
        $this->assertSame('1K', $this->moteur->rendu($this->moteur->forLine('Cheese Burger', $this->snap([]))['pieces']));
        $this->assertSame('1Chick 1F', $this->moteur->rendu($this->moteur->forLine('Menu Enfant Chicken Burger', $this->snap([]))['pieces']));
        $this->assertSame('1Chick', $this->moteur->rendu($this->moteur->forLine('Chicken Burger', $this->snap([]))['pieces']));
    }

    /**
     * LES FRITES (owner) — « le nombre de menu tu mets 5F », « une grande frite c'est
     * automatiquement 2F ». Elles vont au bain de friture : elles font partie de ce qu'il
     * faut cuire.
     */
    public function test_les_frites_sont_comptees_par_menu_et_doublees_si_grandes(): void
    {
        $menu = ['addons' => [
            ['role' => 'menu_frites', 'quantity' => 1, 'addon_name' => 'Frites'],
            ['role' => 'menu_boisson', 'quantity' => 1, 'addon_name' => 'Coca 33cl'],
        ]];
        $snapMenu = array_merge($this->snap(['Viande Hachée']), $menu);

        $this->assertSame('2K 1F', $this->moteur->rendu($this->moteur->forLine('Tacos M', $snapMenu, 1)['pieces']));
        $this->assertSame('10K 5F', $this->moteur->rendu($this->moteur->forLine('Tacos M', $snapMenu, 5)['pieces']), '5 menus = 5F, comme demandé.');
        $this->assertSame('1F', $this->moteur->rendu($this->moteur->forLine('Frites', $this->snap([]))['pieces']));
        $this->assertSame('2F', $this->moteur->rendu($this->moteur->forLine('Grande Frite', $this->snap([]))['pieces']));
    }

    /** La frite d'un menu enfant est déjà dans sa recette : elle ne doit pas être comptée deux fois. */
    public function test_la_frite_du_menu_enfant_nest_jamais_comptee_deux_fois(): void
    {
        $snap = array_merge($this->snap([]), ['addons' => [['role' => 'menu_frites', 'quantity' => 1, 'addon_name' => 'Frites']]]);

        $this->assertSame('6Nug 1F', $this->moteur->rendu($this->moteur->forLine('Menu Enfant Nuggets', $snap, 1)['pieces']));
    }

    /** Un burger futur, sans recette documentée, doit s'annoncer « ? » plutôt que disparaître. */
    public function test_un_burger_sans_recette_documentee_reste_signale(): void
    {
        $r = $this->moteur->forLine('Mystery Burger', $this->snap([]), 1);

        $this->assertTrue($r['inconnu'], 'Un burger inconnu ne doit jamais quitter le bandeau en silence.');
        $this->assertSame([], $r['pieces'], 'Aucune pièce ne doit être inventée.');
    }

    /**
     * Un produit qui ne demande AUCUNE cuisson (boisson, dessert) ne produit ni pièce ni point
     * d'interrogation. Les frites, elles, en produisent — elles vont au bain de friture.
     */
    public function test_un_produit_sans_cuisson_ne_produit_rien(): void
    {
        $r = $this->moteur->forLine('Coca 33cl', $this->snap([]), 1);

        $this->assertFalse($r['inconnu']);
        $this->assertSame('', $this->moteur->rendu($r['pieces'], 0));
    }

    /**
     * L'AGRÉGATION — le cœur de la demande owner : « si on a dans toute la commande plusieurs,
     * on va tous les assembler et dire une seule fois qu'il y en a neuf ».
     * K vient en tête : c'est la viande la plus longue à cuire.
     */
    public function test_toute_la_commande_est_agregee_en_une_seule_ligne(): void
    {
        $o = $this->moteur->forOrder([
            ['name' => 'Tacos M', 'snapshot' => $this->snap(['Viande Hachée']), 'quantity' => 3],   // 6K
            ['name' => 'Méga', 'snapshot' => $this->snap(['Viande Hachée', 'Poulet mariné']), 'quantity' => 2], // 2K 2P
            ['name' => 'Galette Cayenne', 'snapshot' => $this->snap(['Poulet mariné']), 'quantity' => 1],       // 2P
            ['name' => 'Frites', 'snapshot' => $this->snap([]), 'quantity' => 2],                              // 2F
        ]);

        $this->assertSame('8K 2P 2F', $o['texte']);
        $this->assertSame(0, $o['inconnus']);
    }

    /**
     * L'EXEMPLE DE L'OWNER, mot pour mot : « trois sandwichs mixtes qui contiennent du poulet
     * […] et un Cayenne complet qui contient juste du poulet […] on va noter qu'il y a deux
     * portions et demie, ça veut dire 2,5 ».
     * 3 × 0,5 portion (mixtes) + 1 portion (Cayenne entier) = 2,5P.
     */
    public function test_lexemple_owner_donne_bien_deux_portions_et_demie_de_poulet(): void
    {
        $o = $this->moteur->forOrder([
            ['name' => 'Tacos L', 'snapshot' => $this->snap(['Poulet mariné', 'Viande Hachée']), 'quantity' => 2],
            ['name' => 'Tacos L', 'snapshot' => $this->snap(['Poulet mariné', 'Cordon Bleu']), 'quantity' => 1],
            ['name' => 'Cayenne', 'snapshot' => $this->snap(['Poulet mariné']), 'quantity' => 1],
        ]);

        $this->assertStringContainsString('2,5P', $o['texte']);
        $this->assertSame('2K 2,5P 1Cordon', $o['texte']);
    }

    /** Les recettes inconnues sont comptées à part et annoncées, pas noyées dans les pièces. */
    public function test_les_recettes_inconnues_sont_comptees_a_part_dans_la_commande(): void
    {
        $o = $this->moteur->forOrder([
            ['name' => 'Tacos M', 'snapshot' => $this->snap(['Viande Hachée']), 'quantity' => 1],
            ['name' => 'Mystery Burger', 'snapshot' => $this->snap([]), 'quantity' => 2],
        ]);

        $this->assertSame(2, $o['inconnus']);
        $this->assertSame('2K 2×?', $o['texte']);
    }

    /**
     * LA COMMANDE COMPLÈTE telle que l'owner la décrit : 5 menus tacos hachée (10K + 5F),
     * un Big Burger (3K) et une grande frite (2F) → une seule ligne, « 13K 7F ».
     */
    public function test_une_commande_reelle_melant_viandes_menus_et_frites(): void
    {
        $menu = ['addons' => [['role' => 'menu_frites', 'quantity' => 1, 'addon_name' => 'Frites']]];

        $o = $this->moteur->forOrder([
            ['name' => 'Tacos M', 'snapshot' => array_merge($this->snap(['Viande Hachée']), $menu), 'quantity' => 5],
            ['name' => 'Big Burger', 'snapshot' => $this->snap([]), 'quantity' => 1],
            ['name' => 'Grande Frite', 'snapshot' => $this->snap([]), 'quantity' => 1],
        ]);

        $this->assertSame('13K 7F', $o['texte']);
        $this->assertSame(0, $o['inconnus']);
    }

    /**
     * CONTRE-PREUVE — une ligne de snapshot qui n'est PAS une viande ne doit jamais atterrir
     * sur la plancha. Sans cette garde, la sauce Algérienne présente dans chaque fixture
     * ci-dessus se compterait comme une portion à cuire.
     */
    public function test_une_ligne_qui_nest_pas_une_viande_natteint_jamais_la_plancha(): void
    {
        $snapshot = ['lines' => [
            ['attribute_name' => 'Sauce 1', 'variation_name' => 'Algérienne'],
            ['attribute_name' => 'Crudités', 'variation_name' => 'Salade'],
            ['attribute_name' => 'Taille', 'variation_name' => 'M'],
        ]];

        $r = $this->moteur->forLine('Tacos M', $snapshot, 1);

        $this->assertSame([], $r['pieces'], 'Une sauce, une crudité ou une taille ne sont pas des viandes à cuire.');
    }
}
