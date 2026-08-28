<?php

namespace Tests\Feature\Onboarding;

use App\Models\RawMaterial;
use App\Services\Purchasing\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB-08 2026-08-28 · P0] Une facture en KILOS ne doit pas créditer des GRAMMES.
 *
 * `PurchaseService` passait `(float) $line->qty` directement au stock, sans jamais
 * comparer `purchase_lines.unit` à `raw_materials.unit`. Les deux colonnes existent
 * pourtant depuis la création des tables.
 *
 * MESURÉ SUR LA BASE RÉELLE : les matières se comptent en `g`, `piece`, `tranche` ;
 * les factures arrivent en `kg`, `piece`, `tranche`. Une ligne « Poulet frais 3kg »
 * créditait donc **3 grammes** — un facteur MILLE.
 *
 * Ce n'est pas théorique : **11 des 14 matières stockées sont négatives** (Poulet
 * −9 600 g, Viande hachée −3 975 g). « Conso & Stock » annonce 17 ruptures sur 20
 * pendant que la borne vend tous les burgers, et le coût moyen pondéré — calculé
 * avec la même quantité — est faux du même facteur.
 *
 * DEUX COMPORTEMENTS, et le second compte autant que le premier :
 *   · conversion quand elle est CONNUE ;
 *   · REFUS NOMMÉ quand elle ne l'est pas. Créditer un nombre dont on ignore
 *     l'unité est exactement ce qui a corrompu ce stock. Un refus se corrige ;
 *     une corruption silencieuse se découvre des mois plus tard.
 *
 * ⚠️ CE CORRECTIF NE RÉPARE PAS L'HISTORIQUE. Les quantités déjà écrites restent
 * fausses ; les remettre d'aplomb est une décision du propriétaire, pas un effet de
 * bord d'un correctif de code.
 */
class UneFactureEnKilosNeCreditePasDesGrammesTest extends TestCase
{
    use RefreshDatabase;

    /** Invoque la conversion privée sur une ligne factice. */
    private function convertir(string $uniteFacture, float $qty, string $uniteMatiere): float
    {
        // `raw_materials` porte une contrainte d'unicité sur (branch_id, name) :
        // chaque appel doit créer une matière distincte, sinon le second cas d'un
        // même test échoue sur la contrainte et non sur la conversion.
        static $rang = 0;
        $rang++;

        $matiere = RawMaterial::query()->create([
            'name' => 'Poulet ' . $rang,
            'unit' => $uniteMatiere,
        ]);

        $ligne = (object) ['qty' => $qty, 'unit' => $uniteFacture];

        $service = app(PurchaseService::class);
        $methode = new \ReflectionMethod($service, 'quantiteDansLUniteDeLaMatiere');
        $methode->setAccessible(true);

        return (float) $methode->invoke($service, $ligne, (int) $matiere->id);
    }

    public function test_trois_kilos_creditent_trois_mille_grammes(): void
    {
        // LE DÉFAUT, dans sa forme exacte : la ligne réelle #9 de la base,
        // « Poulet frais 3kg », qty=3, unit='kg', vers une matière en `g`.
        $this->assertSame(
            3000.0,
            $this->convertir('kg', 3.0, 'g'),
            "Trois kilos doivent créditer 3 000 grammes.\n"
            . "Sans conversion, la matière recevait 3 — un facteur mille, et c'est ce\n"
            . 'qui a rendu 11 des 14 matières négatives.'
        );
    }

    public function test_une_unite_identique_ne_change_rien(): void
    {
        // Contrôle négatif : la conversion ne doit pas déplacer ce qui allait bien.
        $this->assertSame(12.0, $this->convertir('piece', 12.0, 'piece'));
        $this->assertSame(5.0, $this->convertir('tranche', 5.0, 'tranche'));
        $this->assertSame(250.0, $this->convertir('g', 250.0, 'g'));
    }

    public function test_la_casse_et_les_espaces_ne_cassent_pas_la_reconnaissance(): void
    {
        $this->assertSame(2000.0, $this->convertir(' KG ', 2.0, 'g'));
    }

    public function test_les_volumes_sont_convertis_aussi(): void
    {
        $this->assertSame(1500.0, $this->convertir('l', 1.5, 'ml'));
        $this->assertSame(50.0, $this->convertir('cl', 5.0, 'ml'));
    }

    public function test_une_conversion_INCONNUE_est_refusee_et_non_devinee(): void
    {
        // LE POINT LE PLUS IMPORTANT. Deviner est ce qui a corrompu ce stock : on
        // refuse, en nommant la matière et les deux unités, pour que le commerçant
        // sache quoi corriger.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Poulet/');  // le nom doit figurer dans le refus

        $this->convertir('carton', 4.0, 'g');
    }

    public function test_une_unite_absente_ne_fait_pas_echouer_la_reception(): void
    {
        // Une ligne sans unité ne doit pas bloquer : on ne convertit simplement pas.
        // Refuser ici punirait des données anciennes sans rien protéger.
        $this->assertSame(7.0, $this->convertir('', 7.0, 'g'));
    }

    /**
     * [ONB 2026-08-28] LA FRONTIÈRE, corrigée après mesure sur les données réelles.
     *
     * Ma première version refusait TOUTE conversion inconnue. Sur les dix lignes
     * d'achat réellement en base, elle en bloquait SIX :
     *
     *     tranche -> piece   5 lignes
     *     kg      -> g       4 lignes   (le vrai défaut)
     *     kg      -> piece   1 ligne
     *
     * Or `InvoiceClassificationService:108` écrit `'unit' => $line['unit'] ?? 'piece'` :
     * « piece » est la valeur de REPLI quand l'analyse ne sait pas lire l'unité. Mon
     * refus transformait donc une corruption silencieuse en blocage complet du
     * document dès que l'OCR hésitait — et l'exception traversant la transaction,
     * c'est la réception ENTIÈRE qui échouait, lignes saines comprises.
     *
     * La bonne frontière n'est pas « connu / inconnu » mais « dimensionnel / compté ».
     */
    public function test_une_tranche_vers_une_piece_passe_car_un_objet_reste_un_objet(): void
    {
        // Cinq des dix lignes réelles sont exactement ce cas. Aucun facteur ne peut
        // s'y perdre : « 5 tranches » vers une matière comptée en « pièce » vaut 5.
        $this->assertSame(5.0, $this->convertir('tranche', 5.0, 'piece'));
        $this->assertSame(3.0, $this->convertir('piece', 3.0, 'unite'));
        $this->assertSame(2.0, $this->convertir('sachet', 2.0, 'boite'));
    }

    public function test_un_poids_vers_un_compte_reste_refuse(): void
    {
        // Mélanger les deux familles reste un refus : « 3 kg » ne devient pas
        // « 3 pièces ». C'est la dixième ligne de la base, et elle doit bien être
        // corrigée à la main — c'est la seule que ce garde-fou bloque encore.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ne mesurent pas la meme chose/');

        $this->convertir('kg', 3.0, 'piece');
    }
}
