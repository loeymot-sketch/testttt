<?php

namespace Tests\Unit\Hardware;

use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use PHPUnit\Framework\TestCase;

/**
 * [INCIDENT TICKET CUISINE 2026-09-05] « J'ai ajouté des suppléments, ça affiche un
 * chiffre 90 alors que ça n'a rien à voir » — et « les suppléments que je rajoute, sur le
 * ticket ça ne s'affiche même pas ». Les deux plaintes n'en font qu'une, et le coupable
 * est une VIRGULE.
 *
 * L'instruction d'une ligne de commande tient sur une seule ligne de texte :
 *
 *     Sauce : Mayonnaise, Supplément : Œuf (+0,90 €)
 *
 * `extraSauceNames()` capturait tout jusqu'au saut de ligne (`[^\n]+`), puis découpait
 * sur la virgule — **y compris celle du prix `0,90`**. Le morceau « 90 € » devenait un
 * faux nom de sauce, imprimé en tête du ticket cuisine à côté des vrais symboles.
 *
 * Reproduit sur la production, commande 929 / ligne 2184 :
 *     DOUBLE CHEESE | K | MAY 90
 *                          ^^ la 2ᵉ sauce payée 0,50 € n'a ni ligne ni nom
 * et commande 668, où « Olives » sort DEUX fois : en fausse sauce « OLI » puis en
 * « * Olives ». **61 lignes de commande concernées depuis le 2026-08-01.**
 *
 * Second effet, plus grave : ce jeton parasite gonfle le budget de sauces, lequel
 * MASQUE ensuite la vraie ligne « + Sauce supplémentaire ». D'où le supplément qui
 * « ne s'affiche même pas ».
 *
 * Ce n'est PAS une régression récente : les deux constructeurs de ticket sont inchangés
 * depuis le 2026-08-25. Le défaut est ancien, il n'attendait qu'un supplément payant
 * saisi à la caisse sur la même ligne qu'une sauce.
 */
class KitchenTicketVirguleDuPrixTest extends TestCase
{
    private KitchenTicketSymbolicFormatter $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new KitchenTicketSymbolicFormatter;
    }

    public function test_la_virgule_d_un_prix_ne_fabrique_pas_une_fausse_sauce(): void
    {
        // Forme exacte de la commande 929.
        $noms = $this->f->extraSauceNames('Sauce : Mayonnaise, Supplément : Œuf (+0,90 €)');

        // Avant le correctif : ['Supplément : Œuf (+0', '90 €)'] — d'où le « 90 » imprimé.
        $this->assertSame([], $noms, 'aucune sauce en plus dans cette commande');
        foreach ($noms as $n) {
            $this->assertStringNotContainsString('90', $n, "« $n » vient du prix, pas de la carte");
            $this->assertStringNotContainsString('Supplément', $n, "« $n » est un supplément, pas une sauce");
        }
    }

    public function test_un_supplement_sur_la_meme_ligne_n_est_jamais_pris_pour_une_sauce(): void
    {
        // La 1ʳᵉ sauce est la variation gratuite : elle est retirée par le service.
        // Ce qui reste doit être VIDE ici — il n'y a pas de 2ᵉ sauce dans cette commande.
        $noms = $this->f->extraSauceNames('Sauce : Mayonnaise, Supplément : Olives (+0,90 €)');

        $this->assertSame([], $noms, 'aucune sauce en plus : seul un supplément suit');
    }

    public function test_une_VRAIE_deuxieme_sauce_reste_reconnue(): void
    {
        // Le garde ne doit rien retirer d'utile : c'est la moitié qui compte.
        $noms = $this->f->extraSauceNames('Sauce : Mayonnaise, Samouraï');

        $this->assertSame(['Samouraï'], $noms);
    }

    public function test_deux_vraies_sauces_en_plus_survivent_a_un_supplement_qui_suit(): void
    {
        $noms = $this->f->extraSauceNames('Sauce : Mayonnaise, Samouraï, Harissa, Supplément : Œuf (+0,90 €)');

        $this->assertSame(['Samouraï', 'Harissa'], $noms);
    }

    public function test_les_autres_rubriques_bornent_aussi_la_capture(): void
    {
        foreach (['Viandes : Tenders', 'Formule : Menu', 'Sauce frites : Ketchup'] as $rubrique) {
            $noms = $this->f->extraSauceNames('Sauce : Mayonnaise, Samouraï, '.$rubrique);

            $this->assertSame(['Samouraï'], $noms, "la rubrique « $rubrique » doit borner la capture");
        }
    }

    public function test_le_format_borne_reste_intact(): void
    {
        // La borne écrit UNIQUEMENT les extras, sans la sauce gratuite.
        $this->assertSame(['Samouraï', 'Harissa'], $this->f->extraSauceNames('Sauces en plus : Samouraï, Harissa'));
    }

    public function test_une_instruction_sans_sauce_ne_renvoie_rien(): void
    {
        $this->assertSame([], $this->f->extraSauceNames('Supplément : Œuf (+0,90 €)'));
        $this->assertSame([], $this->f->extraSauceNames(null));
        $this->assertSame([], $this->f->extraSauceNames(''));
    }
}
