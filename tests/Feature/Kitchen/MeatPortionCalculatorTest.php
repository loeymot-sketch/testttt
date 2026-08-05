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
        $this->assertSame('2P', $this->ligne('Galette Normale', ['Poulet mariné']));
        $this->assertSame('2Cordon', $this->ligne('Bol Riz', ['Cordon Bleu']));
    }

    /**
     * RÈGLE 2 — un produit à DEUX viandes reçoit UNE pièce de chacune (deux demi-portions).
     * Owner : « si il est méga les mixtes entre deux viandes, alors ça va être une moitié
     * portion de poulet et une moitié portion de viande hachée ».
     */
    public function test_un_produit_a_deux_viandes_recoit_une_demi_portion_de_chacune(): void
    {
        $this->assertSame('1K 1P', $this->ligne('Méga', ['Viande Hachée', 'Poulet mariné']));
        $this->assertSame('1Cordon 1Mex', $this->ligne('Tacos L', ['Mexicanos', 'Cordon Bleu']));
    }

    /** Deux fois la MÊME viande sur un produit à deux emplacements se recompose en portion pleine. */
    public function test_deux_fois_la_meme_viande_se_recompose_en_portion_pleine(): void
    {
        $this->assertSame('2K', $this->ligne('Terminator', ['Viande Hachée', 'Viande Hachée']));
    }

    /** Un choix « Mixte (hachée + poulet) » occupe UN emplacement, partagé entre ses deux viandes. */
    public function test_le_choix_mixte_partage_son_emplacement_entre_ses_deux_viandes(): void
    {
        $this->assertSame('1K 1P', $this->ligne('Cayenne', ['Mixte (hachée + poulet)']));
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
            '2K 2P',
            $this->ligne('Cayenne', ['Poulet mariné'], 1, $extra, 'Viandes en plus : Viande Hachée'),
            'Le Cayenne apporte 2P ; le supplément hachée apporte une portion complète, soit 2K.'
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
        $this->assertStringContainsString('2P', $rendu);
    }

    /**
     * LES RECETTES INCONNUES — burgers, Suprême et menus enfants n'ont AUCUNE viande déclarée en
     * base. Tant que l'owner ne l'a pas donnée, le moteur doit le DIRE et surtout ne rien
     * inventer : une portion devinée ferait cuire la mauvaise quantité et fausserait le stock.
     */
    public function test_une_recette_inconnue_est_signalee_et_jamais_devinee(): void
    {
        foreach (['Big Burger', 'Cheese Burger', 'Suprême', 'Menu Enfant Nuggets'] as $item) {
            $r = $this->moteur->forLine($item, $this->snap([]), 1);
            $this->assertTrue($r['inconnu'], "« {$item} » n'a pas de viande déclarée : le moteur doit le signaler.");
            $this->assertSame([], $r['pieces'], "« {$item} » ne doit produire AUCUNE pièce inventée.");
        }
    }

    /** Un produit sans viande du tout (frites, boisson) ne produit ni pièce ni point d'interrogation. */
    public function test_un_produit_sans_viande_ne_produit_rien(): void
    {
        $r = $this->moteur->forLine('Frites', $this->snap([]), 1);

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
            ['name' => 'Frites', 'snapshot' => $this->snap([]), 'quantity' => 2],                              // rien
        ]);

        $this->assertSame('8K 4P', $o['texte']);
        $this->assertSame(0, $o['inconnus']);
    }

    /** Les recettes inconnues sont comptées à part et annoncées, pas noyées dans les pièces. */
    public function test_les_recettes_inconnues_sont_comptees_a_part_dans_la_commande(): void
    {
        $o = $this->moteur->forOrder([
            ['name' => 'Tacos M', 'snapshot' => $this->snap(['Viande Hachée']), 'quantity' => 1],
            ['name' => 'Big Burger', 'snapshot' => $this->snap([]), 'quantity' => 2],
        ]);

        $this->assertSame(2, $o['inconnus']);
        $this->assertSame('2K 2×?', $o['texte']);
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
