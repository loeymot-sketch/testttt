<?php

namespace Tests\Feature\Items;

use App\Imports\ItemImport;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * [ONB-02 / agent ROUGE 2026-08-27] Le trou de la TVA ne s'était pas bouché : il
 * s'était déplacé.
 *
 * Rendre `tax_id` obligatoire dans `ItemRequest` ne protégeait que le formulaire.
 * L'import Excel n'appelle jamais cette FormRequest : il a ses propres règles
 * (`ItemImport::rules()`), où `tax` était `nullable`, et un `getTaxId()` qui
 * renvoyait `null` dès que le taux du fichier ne correspondait à aucune ligne.
 * Résultat : un article importé pouvait naître à `tax_id = NULL`, que
 * `PricingService` facture ensuite à 0 % sans rien signaler.
 *
 * C'est un agent adverse chargé de casser mon correctif qui l'a trouvé — pas moi,
 * et pas la suite de tests, qui était verte.
 *
 * Ce test garde les deux portes : la règle, et le refus explicite d'un taux inconnu.
 */
class ImportTaxeObligatoireTest extends TestCase
{
    use RefreshDatabase;

    private function reglesImport(): array
    {
        return (new ItemImport('fichier-fictif.xlsx'))->rules();
    }

    public function test_la_colonne_taxe_est_obligatoire_a_l_import(): void
    {
        $regles = $this->reglesImport();

        $this->assertArrayHasKey('tax', $regles);
        $this->assertContains(
            'required',
            $regles['tax'],
            "Sans 'required', une colonne « tax » vide crée un article hors taxe : "
            . "l'import contourne entièrement ItemRequest."
        );
    }

    public function test_une_ligne_sans_taxe_est_refusee(): void
    {
        $ligne = [
            'name'      => 'Kebab importé',
            'category'  => 'Sandwichs',
            'item_type' => 1,
            'price'     => 9.50,
            'featured'  => 0,
            'status'    => 5,
        ];

        $validateur = Validator::make($ligne, $this->reglesImport());

        $this->assertTrue($validateur->fails(), 'Une ligne sans taux de TVA doit être refusée.');
        $this->assertArrayHasKey('tax', $validateur->errors()->toArray());
    }

    public function test_un_taux_inconnu_est_refuse_avec_un_message_utile(): void
    {
        Tax::create([
            'name' => 'TVA 10 %', 'code' => 'T10', 'tax_rate' => 10,
            'type' => 1, 'status' => 5,
        ]);

        $import = new ItemImport('fichier-fictif.xlsx');

        $methode = new \ReflectionMethod($import, 'getTaxId');
        $methode->setAccessible(true);

        // Un taux existant se résout.
        $this->assertIsInt($methode->invoke($import, 10));

        // Un taux absent ne doit PLUS renvoyer null en silence.
        try {
            $methode->invoke($import, 33);
            $this->fail('Un taux inconnu doit lever une exception, pas produire un article hors taxe.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('33', $e->getMessage());
            $this->assertStringContainsString(
                'Réglages',
                $e->getMessage(),
                "Le message doit dire au commerçant OÙ créer la taxe manquante."
            );
        }
    }

    public function test_le_numero_de_caisse_est_saisissable(): void
    {
        // Trouvé par le même agent adverse : `register_id` est lu par le moteur de
        // ticket au même titre que siret/vat_intra/legal_footer, mais n'avait aucune
        // règle — j'avais comblé trois champs sur quatre en annonçant le trou bouché.
        $regles = (new \App\Http\Requests\BranchRequest())->rules();

        $this->assertArrayHasKey(
            'register_id',
            $regles,
            'register_id est imprimé sur le ticket : sans règle, il ne peut jamais être saisi.'
        );
    }
}
