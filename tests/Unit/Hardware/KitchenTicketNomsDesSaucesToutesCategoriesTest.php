<?php

namespace Tests\Unit\Hardware;

use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use PHPUnit\Framework\TestCase;

/**
 * [GOAL CAISSE/CUISINE #4 2026-10-02] « Avec deux sauces (ou plus), le ticket affiche
 * « sauce supplémentaire » sans le nom de la sauce. Ça revient à chaque fois. »
 *
 * CAUSE RACINE COMMUNE (vérifiée, pas supposée) : les wizards (gelés) vendent la sauce en plus
 * comme un extra GÉNÉRIQUE sans nom (« Sauce supplémentaire » × N). Le NOM n'existe que dans le
 * texte libre `instruction`, que chaque rendu re-découpe. Or la caisse écrit
 *
 *     Sauce : Algérienne, Andalouse, Harissa Supplément : Cheddar (+€0.90)
 *
 * — une ESPACE, pas une virgule, entre la dernière sauce et « Supplément : ». Le découpage
 * s'arrêtait au premier « : » rencontré, donc « Harissa Supplément : Cheddar » était jeté EN
 * ENTIER : la dernière sauce disparaissait dès qu'un supplément suivait. Les anciens tests
 * utilisaient une virgule (« …, Supplément : Œuf ») : ils passaient pendant que la production
 * échouait. Ces tests-ci utilisent la forme RÉELLE.
 *
 * Même défaut ré-écrit à l'écriture dans `sauce_destinations` (snapshot scellé, immuable) : un
 * tableau tronqué était préféré à une relecture correcte → corrigé côté lecture.
 *
 * Une ligne par catégorie de produits qui porte des sauces (noms RÉELS de la table items).
 */
class KitchenTicketNomsDesSaucesToutesCategoriesTest extends TestCase
{
    private KitchenTicketSymbolicFormatter $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new KitchenTicketSymbolicFormatter;
    }

    /** @return array<string, array{0:string,1:string}> catégorie => [nom du produit, libellé pour le message] */
    public static function categories(): array
    {
        return [
            'Sandwichs' => ['Cayenne'],
            'Galette' => ['Galette Cayenne'],
            'Burgers' => ['Cheese Burger'],
            'Tacos' => ['Tacos M'],
            'Bols' => ['Bol Frites'],
            'Frites' => ['Petite Frites'],
            'Menu enfant' => ['Menu Enfant Chicken Burger'],
        ];
    }

    /** Snapshot : 1ʳᵉ sauce = variation gratuite ; les suivantes = extra générique × N. */
    private function snapshot(string $first, int $extraQty, ?array $sealedProduct = null, bool $withCheddar = true): array
    {
        $extras = [['extra_name' => 'Sauce supplémentaire', 'unit_price' => 0.50, 'line_total' => 0.50 * $extraQty, 'quantity' => $extraQty]];
        if ($withCheddar) {
            $extras[] = ['extra_name' => 'Cheddar', 'unit_price' => 0.90, 'line_total' => 0.90, 'quantity' => 1];
        }
        $snap = [
            'lines' => [['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => $first]],
            'extras' => $extras,
            'addons' => [],
        ];
        if ($sealedProduct !== null) {
            $snap['sauce_destinations'] = ['product' => $sealedProduct, 'fries' => []];
        }

        return $snap;
    }

    private function rendered(string $item, array $snap, string $instruction): array
    {
        return [
            'main' => $this->f->mainLine($item, $snap, $instruction),
            'supp' => $this->f->supplementLines($snap, $instruction),
        ];
    }

    private function assertToutesLesSaucesNommees(array $r, array $sauces, string $ctx): void
    {
        foreach ($r['supp'] as $line) {
            $this->assertStringNotContainsStringIgnoringCase('Sauce supplémentaire', $line, "$ctx : le libellé générique ne doit plus s'afficher : ".json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        foreach ($sauces as $sauce) {
            $sym = $this->f->sauceSymbol($sauce);
            $this->assertNotSame('', $sym, "$ctx : symbole introuvable pour $sauce");
            $this->assertStringContainsString($sym, $r['main'].' '.implode(' ', $r['supp']), "$ctx : la sauce « $sauce » ($sym) doit apparaître : ".json_encode($r, JSON_UNESCAPED_UNICODE));
        }
    }

    /** @dataProvider categories */
    public function test_caisse_trois_sauces_puis_un_supplement_sur_la_meme_ligne(string $item): void
    {
        // Forme RÉELLE de pos-wizard.js : espace (pas de virgule) avant « Supplément : ».
        $instruction = strtoupper($item)."\nPain Viandes : Poulet mariné - Salade, Tomate Sauce : Algérienne, Andalouse, Harissa Supplément : Cheddar (+€0.90)";
        $snap = $this->snapshot('Algérienne', 2);

        $this->assertSame(['Andalouse', 'Harissa'], $this->f->extraSauceNames($instruction), 'les 2 sauces en plus doivent être récupérées avec leur nom');
        $this->assertToutesLesSaucesNommees($this->rendered($item, $snap, $instruction), ['Algérienne', 'Andalouse', 'Harissa'], $item);
    }

    /** @dataProvider categories */
    public function test_caisse_deux_sauces_puis_un_supplement(string $item): void
    {
        $instruction = strtoupper($item)."\nPain Sauce : Mayonnaise, Samouraï Supplément : Cheddar (+€0.90)";
        $snap = $this->snapshot('Mayonnaise', 1);

        $this->assertSame(['Samouraï'], $this->f->extraSauceNames($instruction));
        $this->assertToutesLesSaucesNommees($this->rendered($item, $snap, $instruction), ['Mayonnaise', 'Samouraï'], $item);
    }

    /** @dataProvider categories */
    public function test_snapshot_scelle_tronque_ne_masque_pas_la_relecture_correcte(string $item): void
    {
        // Ligne réelle en base : sauce_destinations.product = ["Curry"] alors que l'instruction
        // porte « Barbecue, Curry » (+ un supplément). Le tableau scellé est immuable : on ne peut
        // pas le réparer en base, donc la lecture doit préférer la relecture plus complète.
        $instruction = strtoupper($item)."\nSauce : Barbecue, Curry Supplément : Cheddar (+€0.90)";
        $snap = $this->snapshot('Barbecue', 1, ['Curry']);

        $this->assertToutesLesSaucesNommees($this->rendered($item, $snap, $instruction), ['Barbecue', 'Curry'], $item);
    }

    /** @dataProvider categories */
    public function test_borne_libelle_francais_sauces_en_plus(string $item): void
    {
        $instruction = 'Sauces en plus : Andalouse, Harissa. Supplément : Cheddar (+0,90 €)';
        $snap = $this->snapshot('Algérienne', 2);

        $this->assertSame(['Andalouse', 'Harissa'], $this->f->extraSauceNames($instruction));
        $this->assertToutesLesSaucesNommees($this->rendered($item, $snap, $instruction), ['Algérienne', 'Andalouse', 'Harissa'], $item);
    }

    /** @dataProvider categories */
    public function test_borne_libelle_arabe_est_reconnu(string $item): void
    {
        // resources/js/languages/ar.json : « صلصات إضافية: {list} » — aucune regex ne le reconnaissait.
        $instruction = 'صلصات إضافية: Andalouse, Harissa';
        $snap = $this->snapshot('Algérienne', 2, null, false);

        $this->assertSame(['Andalouse', 'Harissa'], $this->f->extraSauceNames($instruction));
        $this->assertToutesLesSaucesNommees($this->rendered($item, $snap, $instruction), ['Andalouse', 'Harissa'], $item);
    }

    public function test_les_deux_sauces_en_plus_sans_supplement_restent_correctes(): void
    {
        // Contre-épreuve : le cas qui marchait déjà ne doit pas régresser.
        $this->assertSame(['Andalouse', 'Harissa'], $this->f->extraSauceNames('Sauce : Algérienne, Andalouse, Harissa'));
    }

    /**
     * [GOAL REMARQUES 2026-10-03 · T-1.3 R-070] Les 13 sauces VENDUES SEULES (catégorie « Sauces
     * supplémentaires », migration 2026_10_02_090000 ; noms RÉELS de la table items #245-#257)
     * sortaient toutes « SAU » : le cuisinier ne savait pas quelle sauce servir.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function saucesVenduesSeules(): array
    {
        return [
            'Ketchup' => ['Sauce Ketchup', 'SAUCE KETCHUP'],
            'Mayonnaise' => ['Sauce Mayonnaise', 'SAUCE MAYONNAISE'],
            'Blanche' => ['Sauce Blanche', 'SAUCE BLANCHE'],
            'Algérienne' => ['Sauce Algérienne', 'SAUCE ALGERIENNE'],
            'Samouraï' => ['Sauce Samouraï', 'SAUCE SAMOURAI'],
            'Andalouse' => ['Sauce Andalouse', 'SAUCE ANDALOUSE'],
            'Américaine' => ['Sauce Américaine', 'SAUCE AMERICAINE'],
            'Barbecue' => ['Sauce Barbecue', 'SAUCE BARBECUE'],
            'Curry' => ['Sauce Curry', 'SAUCE CURRY'],
            'Harissa' => ['Sauce Harissa', 'SAUCE HARISSA'],
            'Hannibal' => ['Sauce Hannibal', 'SAUCE HANNIBAL'],
            'Fromagère maison' => ['Sauce Fromagère maison', 'SAUCE FROMAGERE MAISON'],
            'Spicy maison' => ['Sauce Spicy maison', 'SAUCE SPICY MAISON'],
        ];
    }

    /** @dataProvider saucesVenduesSeules */
    public function test_une_sauce_vendue_seule_garde_son_nom_en_cuisine(string $item, string $attendu): void
    {
        $this->assertSame($attendu, $this->f->mainLine($item, ['lines' => [], 'extras' => [], 'addons' => []], ''));
    }

    public function test_un_produit_qui_contient_sauce_sans_commencer_par_sauce_reste_en_code_court(): void
    {
        // Contre-épreuve : seule la vente d'une SAUCE (nom commençant par « Sauce ») est écrite en entier.
        $this->assertStringNotContainsString('SAUCE', $this->f->mainLine('Bol Frites', ['lines' => [], 'extras' => [], 'addons' => []], ''));
    }

    public function test_un_prix_a_virgule_et_un_supplement_collant_ne_fabriquent_pas_de_fausse_sauce(): void
    {
        $noms = $this->f->extraSauceNames('Sauce : Mayonnaise, Samouraï Supplément : Œuf (+0,90 €), Olives (+0,90 €)');

        $this->assertSame(['Samouraï'], $noms);
    }
}
