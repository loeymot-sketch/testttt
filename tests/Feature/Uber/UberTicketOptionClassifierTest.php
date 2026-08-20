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

    /**
     * [QUANTITÉ EN TÊTE 2026-08-12] Le ticket écrit « 2 x Cheese Burger ». Sur une VRAIE commande,
     * la lecture a retiré le « 2 x » du titre sans le reporter : un burger manquait, et rien à
     * l'écran ne le signalait. Le filet rattrape le préfixe s'il survit dans le titre.
     *
     * @dataProvider casQuantite
     */
    public function test_la_quantite_ecrite_en_tete_du_produit_n_est_jamais_perdue(string $titre, int $qteLue, int $attendue, string $titreAttendu): void
    {
        $t = \App\Services\Uber\Vision\OpenAiUberTicketVisionService::normalize([
            'items' => [['title' => $titre, 'quantity' => $qteLue, 'options' => [], 'note' => '']],
        ]);

        $this->assertSame($attendue, $t['items'][0]['quantity'], "Mauvaise quantité pour « {$titre} »");
        $this->assertSame($titreAttendu, $t['items'][0]['title'], "Le préfixe doit quitter le titre pour « {$titre} »");
    }

    /** @return array<string, array<int, mixed>> */
    public static function casQuantite(): array
    {
        return [
            'prefixe survivant, quantite ratee' => ['2 x Cheese Burger', 1, 2, 'Cheese Burger'],
            'prefixe collé' => ['3x Tacos M', 1, 3, 'Tacos M'],
            'prefixe et quantite deja lues — pas de doublement' => ['2 x Cheese Burger', 2, 2, 'Cheese Burger'],
            'aucun prefixe' => ['Cheese Burger', 1, 1, 'Cheese Burger'],
            // ⚠️ Un nom de produit qui COMMENCE par un chiffre ne doit pas être amputé.
            'produit chiffre' => ['4 Fromages', 1, 1, '4 Fromages'],
        ];
    }

    /**
     * [RUBRIQUES 2026-08-12] Sur la commande RÉELLE 7B9F2 (BOUDJEMA), la lecture a transformé les
     * en-têtes du ticket en PRODUITS : la cuisine recevait « CRU | TO », « SUP + Chéddar » et une
     * ligne « Menu (Frites + Boisson) » née du seul mot « Boisson » — trois plats fantômes pour
     * une commande qui n'en comptait qu'un. Une seconde lecture du MÊME ticket était propre :
     * la consigne ne suffit pas, il faut une garde déterministe.
     */
    public function test_une_rubrique_du_ticket_n_est_jamais_un_produit(): void
    {
        $t = \App\Services\Uber\Vision\OpenAiUberTicketVisionService::normalize([
            'items' => [
                ['title' => '1 x Menu Sandwich Cayenne', 'quantity' => 1, 'options' => ['PAN: Pain', 'SAUCE: Harissa'], 'note' => ''],
                ['title' => '1 x Crudités', 'quantity' => 1, 'options' => ['1 x Tomate', '1 x Oignon'], 'note' => ''],
                ['title' => '1 x Boisson', 'quantity' => 1, 'options' => ['1 x Thé Glacé'], 'note' => ''],
                ['title' => 'SUPPLÉMENTS', 'quantity' => 1, 'options' => ['1 x Chéddar (recommandé)'], 'note' => ''],
            ],
        ]);

        $this->assertCount(1, $t['items'], 'Les rubriques du ticket sont devenues des plats fantômes.');
        $this->assertSame('Menu Sandwich Cayenne', $t['items'][0]['title']);

        // RIEN n'est perdu au repliement : chaque choix du client rejoint le produit.
        foreach (['Tomate', 'Oignon', 'Thé Glacé', 'Chéddar'] as $attendu) {
            $this->assertStringContainsString(
                $attendu,
                implode(' | ', $t['items'][0]['options']),
                "Le choix « {$attendu} » a disparu du repliement."
            );
        }
    }

    /**
     * [RUBRIQUES 2026-08-12] Les en-têtes arrivent AUSSI comme options — mesuré sur la commande
     * réelle E63F5 : « PAIN », « SAUCE », « CRUDITÉS », « BOISSON » venaient s'ajouter à leurs
     * propres valeurs, et la cuisine lisait « + SAUCE + CRUDITÉS » : deux suppléments fantômes
     * par ligne. On ne jette QUE l'étiquette nue ; la valeur, elle, ne bouge pas.
     */
    public function test_une_etiquette_de_rubrique_nue_ne_devient_pas_un_supplement(): void
    {
        $t = \App\Services\Uber\Vision\OpenAiUberTicketVisionService::normalize([
            'items' => [[
                'title' => 'Menu sandwich Cayenne',
                'quantity' => 1,
                'options' => ['PAIN', '1x Galette', 'SAUCE', '1x Barbecue', 'CRUDITÉS', '1x Salade', 'BOISSON', '1x Lipton Ice Tea'],
                'note' => '',
            ]],
        ]);

        $this->assertSame(
            ['1x Galette', '1x Barbecue', '1x Salade', '1x Lipton Ice Tea'],
            $t['items'][0]['options'],
            'Les étiquettes nues sont restées et deviendraient des suppléments fantômes.'
        );
    }

    /** ⚠️ Une étiquette QUI PORTE UNE VALEUR n'est pas nue : la jeter effacerait le choix du client. */
    public function test_une_etiquette_avec_sa_valeur_survit(): void
    {
        $t = \App\Services\Uber\Vision\OpenAiUberTicketVisionService::normalize([
            'items' => [[
                'title' => 'Cayenne', 'quantity' => 1,
                'options' => ['PAIN: Galette', 'Sauce : Harissa'], 'note' => '',
            ]],
        ]);

        $this->assertSame(['PAIN: Galette', 'Sauce : Harissa'], $t['items'][0]['options']);
    }

    /**
     * ⚠️ « Pain » EST un choix de la carte (Pain ou Galette), pas seulement un titre de rubrique.
     *
     * Une première version de la garde filtrait sans regarder la casse : elle a effacé le pain
     * d'un menu RÉEL (commande E63F5) — la cuisine ne savait plus sur quoi servir le sandwich.
     * Le ticket imprime la rubrique en CAPITALES et la valeur en casse normale : c'est le seul
     * discriminant fiable, et on s'aligne sur le papier.
     */
    public function test_le_pain_choisi_survit_alors_que_la_rubrique_PAIN_disparait(): void
    {
        $t = \App\Services\Uber\Vision\OpenAiUberTicketVisionService::normalize([
            'items' => [[
                'title' => 'Menu sandwich Cayenne', 'quantity' => 1,
                'options' => ['PAIN', 'Pain', 'SAUCE', 'Fromagère maison'], 'note' => '',
            ]],
        ]);

        $this->assertSame(
            ['Pain', 'Fromagère maison'],
            $t['items'][0]['options'],
            'Le pain CHOISI a été effacé avec le titre de rubrique.'
        );
    }

    /** Sans produit au-dessus, on ne replie pas : une ligne visible vaut mieux qu'un choix effacé. */
    public function test_une_rubrique_en_tete_de_ticket_reste_visible(): void
    {
        $t = \App\Services\Uber\Vision\OpenAiUberTicketVisionService::normalize([
            'items' => [
                ['title' => 'CRUDITÉS', 'quantity' => 1, 'options' => ['1 x Tomate'], 'note' => ''],
                ['title' => 'Cheese Burger', 'quantity' => 1, 'options' => [], 'note' => ''],
            ],
        ]);

        $this->assertCount(2, $t['items']);
        $this->assertSame('CRUDITÉS', $t['items'][0]['title']);
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

    /**
     * [RUBRIQUES 2026-08-20 · owner] Les gardes de 2026-08-12 exigeaient le mot NU et RIEN d'autre.
     * Or Uber décore ses en-têtes — « SAUCE : », « SAUCES (2) », « CHOIX DE LA SAUCE » — et
     * `sansAccents()` laisse derrière la ponctuation retirée une espace que l'ancre `$` refusait.
     * Ces formes repartaient donc en LIGNES DE PRODUIT, jamais mappables : c'est l'une des sources
     * du « ART » que l'owner voyait sur chaque ticket scanné, et des « + SAUCE » sur le papier.
     *
     * @dataProvider rubriquesDecorees
     */
    public function test_une_rubrique_decoree_n_est_toujours_pas_un_produit(string $entete): void
    {
        $t = \App\Services\Uber\Vision\OpenAiUberTicketVisionService::normalize([
            'items' => [
                ['title' => 'Cheese Burger', 'quantity' => 1, 'options' => [], 'note' => ''],
                ['title' => $entete, 'quantity' => 1, 'options' => ['1 x Harissa'], 'note' => ''],
            ],
        ]);

        $this->assertCount(1, $t['items'], "« {$entete} » est reparti en plat fantôme.");
        $this->assertSame('Cheese Burger', $t['items'][0]['title']);
        $this->assertContains('1 x Harissa', $t['items'][0]['options'], 'Le choix du client a été perdu au repliement.');
    }

    public static function rubriquesDecorees(): array
    {
        return [
            'deux-points' => ['SAUCE :'],
            'compte entre parenthèses' => ['SAUCES (2)'],
            'accentuée et ponctuée' => ['CRUDITÉS :'],
            'tournure de choix' => ['CHOIX DE LA SAUCE'],
            'paire' => ['SAUCES ET CRUDITÉS'],
            'qualifiée' => ['CRUDITÉS OFFERTES'],
            'au choix' => ['BOISSON AU CHOIX'],
            'payants' => ['SUPPLÉMENTS PAYANTS'],
        ];
    }

    /**
     * ⚠️ Le garde-fou du garde-fou : un VRAI article de la carte dont le nom commence par un mot
     * de rubrique ne doit JAMAIS être replié — ce serait effacer un plat vendu et payé.
     *
     * @dataProvider vraisArticles
     */
    public function test_un_vrai_article_n_est_jamais_pris_pour_une_rubrique(string $titre): void
    {
        $t = \App\Services\Uber\Vision\OpenAiUberTicketVisionService::normalize([
            'items' => [
                ['title' => 'Cheese Burger', 'quantity' => 1, 'options' => [], 'note' => ''],
                ['title' => $titre, 'quantity' => 1, 'options' => [], 'note' => ''],
            ],
        ]);

        $this->assertCount(2, $t['items'], "« {$titre} » est un article de la carte, pas un en-tête.");
        $this->assertSame($titre, $t['items'][1]['title']);
    }

    public static function vraisArticles(): array
    {
        return [
            'boisson seule' => ['Boisson Seule'],   // item #3 de la carte
            'grande frites' => ['Grande Frites'],
            'menu enfant' => ['Menu Enfant Nuggets'],
            'sauce nommée' => ['Sauce Algérienne'],
            'bol' => ['Bol Frites'],
        ];
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
