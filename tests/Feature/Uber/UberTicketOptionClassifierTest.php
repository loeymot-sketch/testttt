<?php

namespace Tests\Feature\Uber;

use App\Services\Uber\UberTicketOptionClassifier as C;
use Tests\TestCase;

/**
 * [UBER-PHOTO 2026-08-10 · owner] Le classement des options lues sur un ticket Uber.
 *
 * C'est la pièce la plus exposée du parcours : mal rangée, une option devient un plat faux.
 * Chaque cas ci-dessous correspond à une forme réellement imprimée par Uber (étiquette avec
 * deux-points, quantité en tête, prix en fin de ligne, ou simple libellé nu).
 *
 * La règle de repli est délibérée : ce que personne ne reconnaît devient un SUPPLÉMENT écrit en
 * toutes lettres. Le cuisinier lit le texte d'origine et décide — c'est toujours préférable à un
 * symbole faux, et infiniment préférable à une option qui disparaît.
 */
class UberTicketOptionClassifierTest extends TestCase
{
    private C $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = new C;
    }

    /**
     * @dataProvider cas
     */
    public function test_chaque_option_va_dans_la_bonne_case(string $option, string $attendu, string $label, int $qte = 1, float $prix = 0.0): void
    {
        $r = $this->c->classify($option);

        $this->assertSame($attendu, $r['kind'], "Mauvaise case pour « {$option} »");
        $this->assertSame($label, $r['label'], "Mauvais libellé pour « {$option} »");
        $this->assertSame($qte, $r['quantity'], "Mauvaise quantité pour « {$option} »");
        $this->assertSame($prix, $r['price'], "Mauvais prix pour « {$option} »");
    }

    /** @return array<string, array<int, mixed>> */
    public static function cas(): array
    {
        return [
            // Étiquette explicite — l'information la plus fiable du ticket.
            'viande étiquetée' => ['Viande : Poulet mariné', C::VIANDE, 'Poulet mariné'],
            'sauce étiquetée' => ['Sauce : Algérienne', C::SAUCE, 'Algérienne'],
            'boisson étiquetée' => ['Boisson : Coca-Cola 33cl', C::BOISSON, 'Coca-Cola 33cl'],
            'support étiqueté' => ['Pain : Galette', C::SUPPORT, 'Galette'],
            'garniture étiquetée' => ['Garniture : Salade', C::CRUDITE, 'Salade'],

            // Sans étiquette : ce sont les tables de la CUISINE qui tranchent.
            'viande nue' => ['Viande Hachée', C::VIANDE, 'Viande Hachée'],
            'sauce nue connue' => ['Sauce Samouraï', C::SAUCE, 'Sauce Samouraï'],
            'crudité nue' => ['Oignons cuits', C::CRUDITE, 'Oignons cuits'],
            'boisson nue' => ['Fanta Orange 33cl', C::BOISSON, 'Fanta Orange 33cl'],

            // La sauce des FRITES est un canal à part : rangée avec les sauces du produit,
            // elle ferait mettre du ketchup dans le tacos.
            'sauce frites étiquetée' => ['Sauce frites : Ketchup', C::SAUCE_FRITES, 'Ketchup'],
            'sauce frites nue' => ['Sauce frites Ketchup', C::SAUCE_FRITES, 'Sauce frites Ketchup'],

            // Formule et accompagnement.
            'formule complète' => ['Menu (Frites + Boisson)', C::MENU, 'Menu (Frites + Boisson)'],
            'frites seules' => ['Frites', C::FRITES, 'Frites'],
            'grandes frites seules' => ['Grande frites', C::FRITES, 'Grande frites'],

            // Quantité en tête et prix en fin de ligne.
            'supplément payant' => ['Supplément Cheddar (+1,00 €)', C::SUPPLEMENT, 'Supplément Cheddar', 1, 1.0],
            'supplément x2' => ['2x Cheddar (+2,00 €)', C::SUPPLEMENT, 'Cheddar', 2, 2.0],
            'viande x2' => ['2× Viande Hachée', C::VIANDE, 'Viande Hachée', 2],

            // Une crudité PAYANTE n'est pas une garniture : c'est un supplément, et le ticket
            // cuisine fait déjà cette distinction pour les commandes maison.
            'oignons frits payants' => ['Oignons frits (+0,90 €)', C::SUPPLEMENT, 'Oignons frits', 1, 0.9],

            // [RETRAIT 2026-08-12] Un ticket Uber s'écrit en NÉGATIF là où nos canaux maison
            // s'écrivent en positif : on ne coche pas « oignons », donc il n'y en a pas. Sur
            // Uber le client écrit « Sans oignons » — et la table des crudités, qui cherche
            // « oignon », le rangeait en garniture. Le ticket cuisine annonçait alors des
            // oignons à quelqu'un qui venait EXPRESSÉMENT de les refuser. Un refus ne doit
            // jamais devenir un ajout.
            'retrait oignons' => ['Sans oignons', C::RETRAIT, 'Sans oignons'],
            'retrait salade' => ['sans salade', C::RETRAIT, 'sans salade'],
            'retrait sans apostrophe' => ["Pas d'oignons", C::RETRAIT, "Pas d'oignons"],
            'retrait pas de' => ['Pas de sauce', C::RETRAIT, 'Pas de sauce'],
            'retrait anglais' => ['No onions', C::RETRAIT, 'No onions'],
            'retrait étiqueté' => ['Retirer : Tomate', C::RETRAIT, 'Tomate'],
            // ⚠️ Le mot « sans » au MILIEU d'un libellé ne retire rien : c'est le nom du produit.
            'sans au milieu n est pas un retrait' => ['Sauce sans gluten', C::SUPPLEMENT, 'Sauce sans gluten'],

            // Repli : inconnu de tous, donc écrit en toutes lettres — jamais perdu.
            'inconnu' => ['Emballage cadeau', C::SUPPLEMENT, 'Emballage cadeau'],
        ];
    }

    /** @test */
    public function une_option_reduite_a_son_etiquette_reste_visible_en_entier(): void
    {
        // « Sauce : » sans valeur n'apprend rien de précis, mais l'effacer effacerait la trace
        // que le client avait demandé quelque chose. L'option survit donc, débarrassée de sa
        // ponctuation, et part en supplément écrit en toutes lettres (« + Sauce ») : le
        // cuisinier voit qu'il manque une information au lieu de ne rien voir du tout.
        $r = $this->c->classify('Sauce :');

        $this->assertSame(C::SUPPLEMENT, $r['kind']);
        $this->assertSame('Sauce', $r['label']);
    }

    /** @test */
    public function une_option_vide_ne_produit_jamais_de_ligne_fantome(): void
    {
        // Le mapper ignore les options vides en amont ; on vérifie ici que le classifieur ne
        // fabrique pas un libellé à partir de rien s'il en reçoit une.
        $r = $this->c->classify('   ');

        $this->assertSame(C::SUPPLEMENT, $r['kind']);
        $this->assertSame('', trim($r['label']));
    }
}
