<?php

namespace Tests\Feature\Items;

use App\Http\Requests\ItemRequest;
use App\Models\Tax;
use Database\Seeders\TaxTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * [ONB-02 T-2.1.2 / T-2.1.3 2026-08-27] Un article ne peut plus naître hors taxe.
 *
 * Le défaut d'origine, vérifié bout en bout :
 *   1. `ItemRequest` acceptait `tax_id` à `null` (la règle interdisait pourtant
 *      explicitement la valeur 0 — le garde-fou existait d'un côté seulement) ;
 *   2. `PricingService.php:240-243` fait `(int) ($dbItem->tax_id ?? 0)` puis
 *      `$taxes[0] ?? null`, et conclut `$taxRate = 0.0` — sans alerte, sans journal ;
 *   3. la commande partait donc facturée à 0 % de TVA, à la borne comme à la caisse.
 *
 * `PricingService` est en zone gelée (CLAUDE.md §7) : on ne le touche pas. On ferme
 * à la source. La défense en profondeur côté moteur de prix — refuser plutôt que
 * facturer à zéro — reste à arbitrer par le propriétaire (gate G-PRIX) : tant
 * qu'elle n'existe pas, tout chemin d'écriture qui contourne cette FormRequest
 * (import Excel, API, reprise de données) rouvre le trou.
 */
class ItemTaxRequiredTest extends TestCase
{
    use RefreshDatabase;

    private function regles(): array
    {
        return (new ItemRequest())->rules();
    }

    private function chargeArticle(array $enPlus = []): array
    {
        return array_merge([
            'name'             => 'Kebab maison ' . uniqid('', true),
            'item_category_id' => 1,
            'item_type'        => 1,
            'price'            => 9.50,
            'is_featured'      => 0,
            'status'           => 5,
            'order'            => 1,
        ], $enPlus);
    }

    public function test_la_taxe_est_obligatoire(): void
    {
        $this->assertContains(
            'required',
            $this->regles()['tax_id'],
            "Sans 'required', un article peut naître avec tax_id NULL, et "
            . 'PricingService le facture alors à 0 % sans rien signaler.'
        );
    }

    public function test_un_article_sans_taxe_est_refuse(): void
    {
        $validateur = Validator::make($this->chargeArticle(), $this->regles());

        $this->assertTrue($validateur->fails(), 'Un article sans taxe doit être refusé.');
        $this->assertArrayHasKey('tax_id', $validateur->errors()->toArray());
    }

    public function test_la_taxe_zero_reste_refusee(): void
    {
        // `not_in:0` existait déjà : c'est la valeur que PricingService fabrique
        // à partir de NULL. On vérifie qu'elle n'est pas devenue acceptable.
        $validateur = Validator::make($this->chargeArticle(['tax_id' => 0]), $this->regles());

        $this->assertTrue($validateur->fails());
        $this->assertArrayHasKey('tax_id', $validateur->errors()->toArray());
    }

    public function test_une_taxe_inexistante_est_refusee(): void
    {
        $validateur = Validator::make($this->chargeArticle(['tax_id' => 999999]), $this->regles());

        $this->assertTrue(
            $validateur->fails(),
            "Un tax_id qui ne correspond à aucune ligne doit être refusé : sinon "
            . 'PricingService ne trouve pas la taxe et retombe sur 0 %.'
        );
    }

    public function test_une_taxe_reelle_passe(): void
    {
        $taxe = Tax::create([
            'name'     => 'TVA 10 % (test)',
            'code'     => 'TEST-VAT-10',
            'tax_rate' => 10,
            'type'     => 1,
            'status'   => 5,
        ]);

        $regles = $this->regles();
        // On isole la seule règle qui nous intéresse : la catégorie et l'unicité
        // du nom dépendent d'un jeu de données que ce test n'a pas à monter.
        $validateur = Validator::make(
            ['tax_id' => $taxe->id],
            ['tax_id' => $regles['tax_id']]
        );

        $this->assertFalse($validateur->fails(), 'Une taxe existante doit être acceptée.');
    }

    public function test_le_socle_fournit_les_taux_francais(): void
    {
        $this->seed(TaxTableSeeder::class);

        $actifs = Tax::query()->where('status', 5)->get();
        $taux = $actifs->pluck('tax_rate')->map(fn ($t) => (float) $t)->all();

        // 10 % restauration, 20 % alcool, 5,5 % alimentaire conditionné.
        $this->assertContains(10.0, $taux, 'Le taux restauration 10 % doit être fourni.');
        $this->assertContains(
            20.0,
            $taux,
            "Le taux 20 % (alcool) manquait au socle : un bar ne pouvait pas déclarer correctement."
        );
        $this->assertContains(5.5, $taux, 'Le taux 5,5 % doit être fourni.');
    }

    public function test_aucun_taux_actif_ne_porte_le_meme_nom_qu_un_autre(): void
    {
        $this->seed(TaxTableSeeder::class);

        // Le socle livrait DEUX taxes nommées « VAT », l'une à 5 % l'autre à 10 % :
        // dans une liste déroulante, un commerçant ne peut pas les distinguer.
        $noms = Tax::query()->where('status', 5)->pluck('name')->all();

        $this->assertSame(
            count($noms),
            count(array_unique($noms)),
            'Deux taux actifs portent le même nom : ils sont indiscernables à la saisie. '
            . 'Noms trouvés : ' . implode(' | ', $noms)
        );
    }
}
