<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Support\Menu\SauceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL WIZARD-CAISSE 2026-08-28 · owner] Sentinelle « une seule carte de sauces ».
 *
 * Ce que ce test empêche de revenir : chaque article porte SA copie des sauces
 * dans `item_variations`. Sans garde, ces copies redivergent. Mesuré en base le
 * 2026-08-28 AVANT correction, sur les 59 articles vendables : CINQ profils de
 * sauces différents pour un menu unique — dont 13 articles sans « Sans sauce »
 * et deux bols avec DEUX sauces au lieu de treize. Symptômes rapportés par le
 * propriétaire : « pas le même ordre d'un sandwich à l'autre », « il manque le
 * choix de mettre pas de sauce », « c'est galère, tu trouves pas la sauce ».
 *
 * On verrouille les deux moitiés de la correction :
 *   · l'ORDRE (SauceCatalog::sortVariations, appliqué par ItemResource pour la
 *     caisse et NormalItemResource pour la borne) ;
 *   · l'indépendance de cet ordre vis-à-vis de l'ordre d'insertion en base.
 */
class SauceCatalogCanonicalOrderSentinelTest extends TestCase
{
    use RefreshDatabase;

    /** Attribut sauce + variations créées dans un ordre volontairement chaotique. */
    private function makeItemWithScrambledSauces(array $sauceNames): Item
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attr = ItemAttribute::factory()->create([
            'name'   => 'Sauce (1ère Gratuite)',
            'status' => Status::ACTIVE,
        ]);

        foreach ($sauceNames as $name) {
            ItemVariation::create([
                'item_id'           => $item->id,
                'item_attribute_id' => $attr->id,
                'name'              => $name,
                'price'             => 0,
                'status'            => Status::ACTIVE,
            ]);
        }

        return $item->fresh('variations');
    }

    public function test_le_tri_rend_l_ordre_canonique_quel_que_soit_l_ordre_d_insertion(): void
    {
        $attendu = collect(SauceCatalog::all())->pluck('name')->all();

        // Deux articles, MÊMES sauces, ordres d'insertion opposés : c'est
        // exactement la situation qui produisait deux écrans différents.
        $ordreA = $attendu;
        $ordreB = array_reverse($attendu);

        $rendus = [];
        foreach ([$ordreA, $ordreB] as $ordre) {
            $item = $this->makeItemWithScrambledSauces($ordre);
            $rendus[] = SauceCatalog::sortVariations($item->variations)->pluck('name')->all();
        }

        $this->assertSame($attendu, $rendus[0], 'Ordre canonique non respecté (insertion croissante).');
        $this->assertSame($attendu, $rendus[1], 'Ordre canonique non respecté (insertion inversée).');
        $this->assertSame($rendus[0], $rendus[1], 'Deux articles aux mêmes sauces rendent des ordres différents.');
    }

    public function test_sans_sauce_est_toujours_en_derniere_position(): void
    {
        $noms = collect(SauceCatalog::all())->pluck('name')->all();

        $this->assertContains('Sans sauce', $noms, '« Sans sauce » a disparu du catalogue : le client ne peut plus refuser la sauce.');
        $this->assertSame('Sans sauce', end($noms), '« Sans sauce » doit rester en dernier, jamais au milieu des vraies sauces.');
    }

    public function test_le_tri_ne_reordonne_pas_les_attributs_non_sauce(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $sauceAttr = ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)', 'status' => Status::ACTIVE]);
        $viandeAttr = ItemAttribute::factory()->create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);

        // Entrelacement volontaire : les sauces N'ÉTANT PAS contiguës en base,
        // un tri global (et non par groupe) devient intransitif et rend un ordre
        // arbitraire. C'est le bug rencontré pendant l'implémentation ; ce test
        // le fige pour qu'il ne revienne pas.
        foreach ([['Harissa', $sauceAttr], ['Poulet', $viandeAttr], ['Ketchup', $sauceAttr],
                  ['Kefta', $viandeAttr], ['Barbecue', $sauceAttr]] as [$name, $attr]) {
            ItemVariation::create([
                'item_id'           => $item->id,
                'item_attribute_id' => $attr->id,
                'name'              => $name,
                'price'             => 0,
                'status'            => Status::ACTIVE,
            ]);
        }

        $trie = SauceCatalog::sortVariations($item->fresh('variations')->variations);

        $viandes = $trie->where('item_attribute_id', $viandeAttr->id)->pluck('name')->values()->all();
        $this->assertSame(['Poulet', 'Kefta'], $viandes, 'Les viandes ont été réordonnées alors que seules les sauces doivent l\'être.');

        $sauces = $trie->where('item_attribute_id', $sauceAttr->id)->pluck('name')->values()->all();
        $this->assertSame(['Ketchup', 'Barbecue', 'Harissa'], $sauces, 'Ordre canonique des sauces non appliqué en présence d\'attributs entrelacés.');
    }

    public function test_les_alias_de_libelle_designent_la_meme_sauce(): void
    {
        // La base contenait « Sauce fromagère maison » ET « Fromagère maison »,
        // « Spicy », « Sauce spicy » et « Spicy maison » — trois écritures pour
        // deux sauces. Sans normalisation, la réparation crée des doublons.
        $this->assertSame(
            SauceCatalog::rank('Fromagère maison'),
            SauceCatalog::rank('Sauce fromagère maison'),
            'Les alias de la sauce fromagère ne pointent pas sur la même entrée.'
        );
        $this->assertSame(
            SauceCatalog::rank('Spicy maison'),
            SauceCatalog::rank('Sauce spicy'),
            'Les alias de la sauce spicy ne pointent pas sur la même entrée.'
        );
        $this->assertSame(SauceCatalog::rank('Barbecue'), SauceCatalog::rank('BBQ'));
    }

    public function test_chaque_sauce_expose_une_couleur_exploitable_par_le_front(): void
    {
        foreach (SauceCatalog::frontPayload() as $entry) {
            $this->assertMatchesRegularExpression(
                '/^#[0-9A-Fa-f]{6}$/',
                $entry['bg'],
                sprintf('Couleur de fond invalide pour « %s » — la tuile caisse serait rendue sans couleur.', $entry['name'])
            );
            $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $entry['fg']);
            $this->assertNotEmpty($entry['aliases'], sprintf('« %s » n\'a aucun alias normalisé.', $entry['name']));
        }
    }
}
