<?php

namespace Tests\Feature\Kiosk;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [F-UPSELL-COMPOSE-GUARD 2026-07-18 / P1-4 + P2-borne(F5)]
 *
 * Le pool upsell borne (`GET /api/frontend/item/kiosk-upsell`) est un ajout
 * 1-tap SANS wizard. Un item qui EXIGE une composition — attribut requis
 * (min_select>=1) ou profil composer publié — ajouté 1-tap produit un payload
 * variations VIDE → OrderRequest REJECT 422 au paiement (bouton Payer mort,
 * cas prouvé item 40 « Menu Enfant Nuggets »). Idem un item 86 (global, par
 * branche via item_branch_availability, ou hors-canal borne) contourne
 * `pruneUnavailableLines` et 422 au quote/paiement.
 *
 * Le fix serveur (ItemController::kioskUpsell) exclut ces items à la SOURCE.
 * Un item SIMPLE (Coca, dessert) doit rester (non-régression).
 */
class KioskUpsellRequiredAttributeExclusionTest extends TestCase
{
    use RefreshDatabase;

    private Tax $tax;
    private ItemCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        $this->tax = Tax::create([
            'name'     => 'VAT-0',
            'code'     => 'VAT0',
            'tax_rate' => 0,
            'type'     => 5,
            'status'   => Status::ACTIVE,
        ]);

        $this->category = ItemCategory::create([
            'name'                 => 'Upsell Pool',
            'slug'                 => 'upsell-pool-test',
            'status'               => Status::ACTIVE,
            'wizard_template'      => 'simple',
            'has_menu'             => false,
            'default_menu_kiosk'   => false,
            'sauce_included_menu'  => false,
            'kiosk_upsell_include' => true,
        ]);
    }

    private function makeUpsellItem(string $name, string $slug, array $overrides = []): Item
    {
        return Item::create(array_merge([
            'name'             => $name,
            'slug'             => $slug,
            'item_category_id' => $this->category->id,
            'tax_id'           => $this->tax->id,
            'price'            => 3.00,
            'status'           => Status::ACTIVE,
            'is_upsell'        => Ask::YES,
            'is_featured'      => Ask::NO,
            'is_available'     => true,
        ], $overrides));
    }

    private function upsellPoolIds(?int $branchId = null): array
    {
        $url = '/api/frontend/item/kiosk-upsell?limit=12';
        if ($branchId !== null) {
            $url .= '&branch_id='.$branchId;
        }
        $response = $this->getJson($url);
        $response->assertStatus(200);

        return collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Non-régression : un item simple, disponible, sans exigence de composition reste suggéré. */
    public function test_simple_available_item_still_appears_in_upsell_pool(): void
    {
        $simple = $this->makeUpsellItem('Coca 33cl', 'coca-33-upsell');

        $this->assertContains($simple->id, $this->upsellPoolIds());
    }

    /** P1-4 : un item à ATTRIBUT REQUIS (min_select>=1) est exclu du pool 1-tap. */
    public function test_item_with_required_attribute_is_excluded(): void
    {
        $simple   = $this->makeUpsellItem('Coca 33cl', 'coca-33-upsell');
        $composed = $this->makeUpsellItem('Menu Enfant Nuggets', 'menu-enfant-nuggets-upsell');

        $attr = ItemAttribute::create([
            'name'         => 'Sauce (1ère Gratuite)',
            'status'       => Status::ACTIVE,
            'min_select'   => 1,
            'max_select'   => 1,
            'allow_repeat' => false,
            'is_available' => true,
        ]);
        ItemVariation::create([
            'item_id'           => $composed->id,
            'item_attribute_id' => $attr->id,
            'name'              => 'Ketchup',
            'price'             => 0,
            'status'            => Status::ACTIVE,
        ]);

        $ids = $this->upsellPoolIds();

        $this->assertNotContains($composed->id, $ids, 'Item à attribut requis ne doit PAS être dans le pool 1-tap');
        $this->assertContains($simple->id, $ids, "L'item simple doit rester");
    }

    /** P1-4 : un attribut OPTIONNEL (min_select=0) n'exclut PAS l'item (défense ciblée). */
    public function test_item_with_optional_attribute_still_appears(): void
    {
        $optional = $this->makeUpsellItem('Frites avec sauce offerte', 'frites-sauce-opt-upsell');

        $attr = ItemAttribute::create([
            'name'         => 'Sauce optionnelle',
            'status'       => Status::ACTIVE,
            'min_select'   => 0,
            'max_select'   => 1,
            'allow_repeat' => false,
            'is_available' => true,
        ]);
        ItemVariation::create([
            'item_id'           => $optional->id,
            'item_attribute_id' => $attr->id,
            'name'              => 'Mayo',
            'price'             => 0,
            'status'            => Status::ACTIVE,
        ]);

        $this->assertContains($optional->id, $this->upsellPoolIds());
    }

    /** P1-4 : un item à PROFIL COMPOSER PUBLIÉ est exclu (exige le wizard). */
    public function test_item_with_published_composer_profile_is_excluded(): void
    {
        $composed = $this->makeUpsellItem('Tacos composable', 'tacos-composable-upsell');

        ItemWizardProfile::create([
            'item_id'         => $composed->id,
            'item_category_id'=> null,
            'template'        => 'custom',
            'version'         => 2,
            'is_published'    => true,
            'published_at'    => now(),
            'branch_id_scope' => null,
        ]);

        $this->assertNotContains($composed->id, $this->upsellPoolIds());
    }

    /** P1-4 : un profil composer NON publié (brouillon) n'exclut PAS l'item. */
    public function test_item_with_unpublished_composer_profile_still_appears(): void
    {
        $draft = $this->makeUpsellItem('Item brouillon profil', 'item-draft-profile-upsell');

        ItemWizardProfile::create([
            'item_id'         => $draft->id,
            'item_category_id'=> null,
            'template'        => 'custom',
            'version'         => 1,
            'is_published'    => false,
            'published_at'    => null,
            'branch_id_scope' => null,
        ]);

        $this->assertContains($draft->id, $this->upsellPoolIds());
    }

    /** P2-borne : un item 86 GLOBALEMENT (is_available=false) est exclu. */
    public function test_globally_unavailable_item_is_excluded(): void
    {
        $available   = $this->makeUpsellItem('Dessert dispo', 'dessert-dispo-upsell');
        $unavailable = $this->makeUpsellItem('Dessert rupture', 'dessert-rupture-upsell', ['is_available' => false]);

        $ids = $this->upsellPoolIds();

        $this->assertContains($available->id, $ids);
        $this->assertNotContains($unavailable->id, $ids);
    }

    /** P2-borne(F5) : un item 86 PAR BRANCHE (item_branch_availability) est exclu quand branch_id est fourni. */
    public function test_branch_unavailable_item_is_excluded_when_branch_id_passed(): void
    {
        $branch = Branch::factory()->create();

        $available   = $this->makeUpsellItem('Boisson dispo branche', 'boisson-dispo-branche-upsell');
        $ruptured    = $this->makeUpsellItem('Boisson rupture branche', 'boisson-rupture-branche-upsell');

        ItemBranchAvailability::create([
            'item_id'            => $ruptured->id,
            'branch_id'          => $branch->id,
            'is_available'       => false,
            'unavailable_reason' => 'stock_rupture',
            'daily_reset_at'     => now()->toDateString(),
        ]);

        $ids = $this->upsellPoolIds($branch->id);

        $this->assertContains($available->id, $ids);
        $this->assertNotContains($ruptured->id, $ids, 'Item 86 par branche ne doit PAS être suggéré');
    }
}
