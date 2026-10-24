<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Services\Kiosk\KioskMenuService;
use App\Support\Menu\RecommendedSupplements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [OWNER 2026-10-21] Suppléments « Recommandés » par catégorie (et par produit pour le Cayenne), pour relever le panier moyen.
 * C'est de la MISE EN AVANT : le prix d'un supplément ne change pas, rien ne devient obligatoire.
 *
 * @group menu
 */
class RecommendedSupplementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_liste_du_produit_prime_sur_celle_de_la_categorie(): void
    {
        // Cayenne : cheddar recommandé (la sauce fromagère est incluse, qui en veut plus va vers le fromage).
        $this->assertTrue(RecommendedSupplements::isRecommended('Cayenne', 'Sandwichs', 'Cheddar'));
        // « Œuf » est recommandé aux autres sandwichs, pas au Cayenne : la liste du produit remplace celle de la catégorie.
        $this->assertFalse(RecommendedSupplements::isRecommended('Cayenne', 'Sandwichs', 'Œuf'));
        $this->assertTrue(RecommendedSupplements::isRecommended('Méga', 'Sandwichs', 'Œuf'));
    }

    public function test_chaque_categorie_a_sa_propre_liste(): void
    {
        foreach (['Raclette', 'Œuf', 'Légumes sautés'] as $extra) {
            $this->assertTrue(RecommendedSupplements::isRecommended('Cheese Burger', 'Burgers', $extra), "burger : $extra");
        }
        $this->assertFalse(RecommendedSupplements::isRecommended('Cheese Burger', 'Burgers', 'Cheddar'));
        $this->assertTrue(RecommendedSupplements::isRecommended('Tacos L', 'Tacos', 'Boursin'));
        $this->assertFalse(RecommendedSupplements::isRecommended('Tacos L', 'Tacos', 'Raclette'));
    }

    public function test_comparaison_sans_casse_ni_accent_et_catégorie_inconnue_sans_recommandation(): void
    {
        $this->assertTrue(RecommendedSupplements::isRecommended('Cheese Burger', 'burgers', 'OEUF'));
        $this->assertTrue(RecommendedSupplements::isRecommended('Cheese Burger', 'Burgers', 'legumes sautes'));
        $this->assertFalse(RecommendedSupplements::isRecommended('Bol Riz', 'Bols', 'Cheddar'));
        $this->assertFalse(RecommendedSupplements::isRecommended(null, null, 'Cheddar'));
        $this->assertFalse(RecommendedSupplements::isRecommended('Cayenne', 'Sandwichs', ''));
    }

    public function test_la_borne_recoit_le_drapeau_sur_les_supplements_seulement(): void
    {
        $filiale = Branch::firstOrCreate(['id' => 1], [
            'name' => 'Filiale de test', 'email' => 't@example.test', 'phone' => '+33600000000', 'city' => 'Lille',
            'state' => 'Hauts-de-France', 'zip_code' => '59000', 'address' => '1 rue de Test', 'status' => Status::ACTIVE,
        ]);
        $categorie = ItemCategory::factory()->create(['name' => 'Burgers', 'status' => Status::ACTIVE]);
        $burger = Item::factory()->create(['name' => 'Cheese Burger', 'item_category_id' => $categorie->id, 'status' => Status::ACTIVE]);
        foreach ([['Raclette', 'supplement'], ['Cheddar', 'supplement'], ['Œuf', 'crudite']] as [$nom, $groupe]) {
            ItemExtra::create(['item_id' => $burger->id, 'name' => $nom, 'price' => 0.9, 'status' => Status::ACTIVE, 'group_label' => $groupe]);
        }

        $menu = app(KioskMenuService::class)->build($filiale);
        $extras = collect($menu['items'])->firstWhere('id', $burger->id)['extras'];
        $recommande = collect($extras)->pluck('is_recommended', 'name')->all();

        $this->assertTrue($recommande['Raclette'], 'un supplément de la liste du burger est recommandé');
        $this->assertFalse($recommande['Cheddar'], 'hors liste : pas recommandé');
        $this->assertFalse($recommande['Œuf'], 'seul un supplément (group_label) est recommandable, pas une crudité');
    }
}
