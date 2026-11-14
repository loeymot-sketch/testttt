<?php

namespace Tests\Feature\Pricing;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\Tax;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [INCIDENT CAISSE 2026-09-03] « Les commandes ne passent pas. »
 *
 * Signalé par le propriétaire, capture à l'appui : au moment d'encaisser, la caisse refuse
 * avec « Composition : le choix #450 n'appartient pas au profil publié. » Le ticket est
 * construit, le montant affiché, la monnaie calculée — et le paiement est impossible.
 *
 * CE QUI SE PASSE, mesuré sur le catalogue réel :
 *
 * Le wizard de caisse facture la 2ᵉ sauce 0,50 € via un extra générique « Sauce
 * supplémentaire » qui appartient bien à l'article (LOCK_CAISSE_SAUCE_SEAL du 2026-07-16).
 * Cet extra porte le groupe `sauce`.
 *
 * Or le profil composeur publié ne décrit d'étapes `extra_group` que pour `crudite` et
 * `supplement`. Le groupe `sauce`, lui, est servi par une étape `item_attribute` — les
 * sauces GRATUITES. Aucune étape ne couvre donc l'extra PAYANT du groupe `sauce`.
 *
 * `PricingService::assertComposerSelectionsBelongToPublishedProfile()` n'autorise que les
 * choix listés par les étapes projetées. Résultat : sur le MÊME article, « Viande
 * supplémentaire » (groupe `supplement`) passe, « Sauce supplémentaire » (groupe `sauce`)
 * bloque la vente. La seule différence est qu'une étape existe pour l'un et pas pour l'autre.
 *
 * Le garde a raison de refuser un choix venu d'un AUTRE article — c'est le risque réel.
 * Il n'a pas à refuser un extra actif, visible, qui appartient à l'article vendu.
 */
class SauceSupplementaireBloqueLaVenteTest extends TestCase
{
    use RefreshDatabase;

    private const PRIX_ARTICLE = 9.80;
    private const PRIX_EXTRA = 0.50;

    /** Reproduit la configuration réelle : profil sans étape pour le groupe `sauce`. */
    private function catalogue(): array
    {
        $this->seedMinimalSettings();
        $branch = Branch::factory()->create();
        $tax = Tax::factory()->create(['tax_rate' => 0]);

        $category = ItemCategory::forceCreate([
            'name' => 'Nos Sandwichs', 'slug' => 'sandwichs-incident', 'status' => Status::ACTIVE,
        ]);

        $item = Item::forceCreate([
            'name' => 'Cayenne',
            'slug' => 'cayenne-incident',
            'price' => self::PRIX_ARTICLE,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $supplement = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Viande supplémentaire',
            'group_label' => 'supplement',
            'price' => self::PRIX_EXTRA,
            'status' => Status::ACTIVE,
        ]);

        $sauce = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Sauce supplémentaire',
            'group_label' => 'sauce',
            'price' => self::PRIX_EXTRA,
            'status' => Status::ACTIVE,
        ]);

        $profile = ItemWizardProfile::query()->create([
            'item_id' => $item->id,
            'name' => 'Profil incident',
            'is_published' => true,
            'published_at' => now(),
        ]);

        // UNE SEULE étape extra_group : `supplement`. Rien pour `sauce` — c'est la
        // configuration réelle du catalogue, pas une invention de ce banc.
        ItemWizardStep::query()->create([
            'profile_id' => $profile->id,
            'step_key' => 'supplement',
            'label' => 'Suppléments',
            'source_type' => 'extra_group',
            'source_ref' => 'supplement',
            'min_select' => 0,
            'max_select' => 5,
            'position' => 0,
            'is_active' => true,
            'visible_on' => ['pos', 'kiosk'],
        ]);

        return [$branch, $item, $supplement, $sauce];
    }

    private function encaisser(int $branchId, int $itemId, array $extras): float
    {
        $line = json_decode(json_encode([
            'item_id' => $itemId,
            'quantity' => 1,
            'item_variations' => [],
            'item_extras' => $extras,
            'item_addons' => [],
        ]));

        $resultat = (new PricingService())->calculateOrder(
            PricingRequest::forPos(0, $branchId, [$line], 0, 0, 0.0, 0.0),
            app(CouponService::class)
        );

        return (float) $resultat->subtotal;
    }

    /** Contrôle : l'extra du groupe COUVERT par une étape passe déjà aujourd'hui. */
    public function test_un_supplement_couvert_par_une_etape_est_encaissable(): void
    {
        [$branch, $item, $supplement] = $this->catalogue();

        $this->assertSame(
            self::PRIX_ARTICLE + self::PRIX_EXTRA,
            round($this->encaisser($branch->id, $item->id, [['id' => $supplement->id, 'quantity' => 1]]), 2)
        );
    }

    /**
     * LE DÉFAUT : même article, même prix, même statut — mais aucune étape ne couvre son
     * groupe. La vente devient impossible.
     */
    public function test_la_sauce_supplementaire_de_l_article_ne_bloque_plus_la_vente(): void
    {
        [$branch, $item, , $sauce] = $this->catalogue();

        $this->assertSame(
            self::PRIX_ARTICLE + self::PRIX_EXTRA,
            round($this->encaisser($branch->id, $item->id, [['id' => $sauce->id, 'quantity' => 1]]), 2),
            'La 2ᵉ sauce est un extra actif de CET article : elle doit être encaissable et facturée.'
        );
    }

    /**
     * Ce que le garde doit continuer de refuser : un choix qui appartient à un AUTRE
     * article. C'est le risque réel — l'injection d'une option non vendue avec ce produit.
     */
    public function test_un_extra_d_un_autre_article_reste_refuse(): void
    {
        [$branch, $item] = $this->catalogue();

        $autre = Item::forceCreate([
            'name' => 'Big Classique',
            'slug' => 'big-classique-incident',
            'price' => 9.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $item->item_category_id,
            'tax_id' => $item->tax_id,
        ]);
        $extraEtranger = ItemExtra::query()->create([
            'item_id' => $autre->id,
            'name' => 'Sauce supplémentaire',
            'group_label' => 'sauce',
            'price' => 99.00,
            'status' => Status::ACTIVE,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->encaisser($branch->id, $item->id, [['id' => $extraEtranger->id, 'quantity' => 1]]);
    }

    /** Et un extra désactivé de l'article reste refusé lui aussi. */
    public function test_un_extra_desactive_reste_refuse(): void
    {
        [$branch, $item] = $this->catalogue();

        $retire = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Sauce retirée de la carte',
            'group_label' => 'sauce',
            'price' => 0.50,
            'status' => Status::INACTIVE,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->encaisser($branch->id, $item->id, [['id' => $retire->id, 'quantity' => 1]]);
    }
}
