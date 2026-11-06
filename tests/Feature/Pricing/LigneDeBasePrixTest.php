<?php

namespace Tests\Feature\Pricing;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\Tax;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GOAL ONB-03 — Critère C1 : LIGNE DE BASE DES PRIX (caractérisation, pré-LOCK).
 *
 * Ce test ne prouve AUCUNE nouvelle règle métier. Il FIGE ce que
 * `PricingService::calculateOrder()` calcule AUJOURD'HUI (HEAD `43b120c7d`),
 * au centime, pour un jeu d'articles représentatif du catalogue (simple,
 * variantes, suppléments payants, composé/wizard) × plusieurs compositions
 * (sans option, option gratuite, option payante). Toute future modification
 * de règle de prix (LOCK G-PRIX §5 du GOAL) devra laisser CE test vert, sauf
 * décision explicite documentée de changer une valeur — auquel cas c'est une
 * régression de prix consciente, jamais un accident.
 *
 * ⛔ Ce test NE MODIFIE PAS `app/Services/Pricing/PricingService.php` (zone
 * gelée CLAUDE.md §7). Il ne fait qu'appeler `calculateOrder()` en lecture
 * (devis), jamais de commande réelle : `orderId=0`, aucun INSERT en table
 * `orders`/`order_items`, RefreshDatabase + sqlite `:memory:` (phpunit.xml).
 *
 * Constat de conception, confirmé par lecture de code (PricingService.php,
 * ComposerProfileProjection.php, assertComposerStepConstraints) : le composer
 * (wizard) actuel ne fait AUCUN calcul de prix spécifique — il valide
 * seulement min/max/allow_repeat/disponibilité des choix. Le prix d'un
 * article composé est exactement la somme des variations/extras sélectionnés,
 * comme un article ordinaire. Pas de mode free/included/paid pour l'instant :
 * c'est précisément ce que le GOAL ONB-03 doit ajouter, sous LOCK G-PRIX,
 * sans faire bouger UNE seule des valeurs figées ici.
 *
 * @see app/Services/Pricing/PricingService.php
 * @see plans/GOAL_ONB03_WIZARD_REGLES_DE_PRIX_2026-08-26.md §5 (T-3.1.1, C1)
 */
class LigneDeBasePrixTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Tax $tax10;
    private Tax $tax20;
    private ItemCategory $category;
    private PricingService $service;
    private CouponService $couponService;

    protected function setUp(): void
    {
        parent::setUp();

        // NF525 : le prix catalogue est TTC, la taxe est EXTRAITE (pas ajoutée).
        // C'est le défaut réel de production (config/pricing.php, .env
        // PRICING_TAX_INCLUSIVE=true) — on le fixe explicitement pour que ce
        // test de ligne de base ne dépende jamais de l'ordre d'exécution des
        // autres suites (plusieurs d'entre elles basculent ce flag à false).
        config(['pricing.tax_inclusive_prices' => true]);

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $this->tax10 = Tax::factory()->create([
            'name' => 'TVA 10%',
            'code' => 'TVA10-ONB03',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10.0,
            'status' => Status::ACTIVE,
        ]);
        $this->tax20 = Tax::factory()->create([
            'name' => 'TVA 20%',
            'code' => 'TVA20-ONB03',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 20.0,
            'status' => Status::ACTIVE,
        ]);

        // Catégorie/article dédiés GOAL-ONB03 — jamais les profils publiés
        // Le Cayenne (§0.1 du GOAL).
        $this->category = ItemCategory::factory()->create([
            'name' => 'GOAL-ONB03 Ligne de base',
            'status' => Status::ACTIVE,
        ]);

        $this->service = new PricingService();
        $this->couponService = app(CouponService::class);
    }

    /* -----------------------------------------------------------------
     * 1) Article SIMPLE — pas de variation, pas d'extra, pas de wizard.
     * ---------------------------------------------------------------*/

    public function test_article_simple_sans_option(): void
    {
        $item = $this->makeItem('GOAL-ONB03 Tacos Simple', 8.80, $this->tax10);

        $out = $this->calc([$this->line($item->id, 1)]);

        $this->assertSame(8.80, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(0.80, $out->lines[0]->taxAmount);
        $this->assertSame(8.80, $out->subtotal);
        $this->assertSame(0.80, $out->totalTax);
        $this->assertSame(8.80, $out->total);
    }

    public function test_article_simple_quantite_multiple(): void
    {
        $item = $this->makeItem('GOAL-ONB03 Tacos Simple', 8.80, $this->tax10);

        $out = $this->calc([$this->line($item->id, 3)]);

        $this->assertSame(26.40, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(2.40, $out->lines[0]->taxAmount);
        $this->assertSame(26.40, $out->total);
    }

    /* -----------------------------------------------------------------
     * 2) Article À VARIANTES (attribut "Taille" : Normale gratuite / Maxi payante).
     * ---------------------------------------------------------------*/

    public function test_article_a_variantes_sans_option(): void
    {
        [$item] = $this->makeItemWithVariantes();

        $out = $this->calc([$this->line($item->id, 1)]);

        $this->assertSame(7.15, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(0.65, $out->lines[0]->taxAmount);
        $this->assertSame(7.15, $out->total);
    }

    public function test_article_a_variantes_option_gratuite(): void
    {
        [$item, $normale] = $this->makeItemWithVariantes();

        $out = $this->calc([$this->line($item->id, 1, [['id' => $normale->id]])]);

        $this->assertSame(0.0, $out->lines[0]->variationTotal);
        $this->assertSame(7.15, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(0.65, $out->lines[0]->taxAmount);
        $this->assertSame(7.15, $out->total);
    }

    public function test_article_a_variantes_option_payante(): void
    {
        [$item, , $maxi] = $this->makeItemWithVariantes();

        $out = $this->calc([$this->line($item->id, 1, [['id' => $maxi->id]])]);

        $this->assertSame(1.50, $out->lines[0]->variationTotal);
        $this->assertSame(8.65, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(0.79, $out->lines[0]->taxAmount);
        $this->assertSame(8.65, $out->total);
    }

    /* -----------------------------------------------------------------
     * 3) Article À SUPPLÉMENTS PAYANTS (Crudités gratuites / Sauce Suppl. payante).
     * ---------------------------------------------------------------*/

    public function test_article_a_supplements_sans_option(): void
    {
        [$item] = $this->makeItemWithSupplements();

        $out = $this->calc([$this->line($item->id, 1)]);

        $this->assertSame(10.80, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(1.80, $out->lines[0]->taxAmount);
        $this->assertSame(10.80, $out->total);
    }

    public function test_article_a_supplements_option_gratuite(): void
    {
        [$item, $crudites] = $this->makeItemWithSupplements();

        $out = $this->calc([$this->line($item->id, 1, [], [['id' => $crudites->id]])]);

        $this->assertSame(0.0, $out->lines[0]->extraTotal);
        $this->assertSame(10.80, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(1.80, $out->lines[0]->taxAmount);
        $this->assertSame(10.80, $out->total);
    }

    public function test_article_a_supplements_option_payante(): void
    {
        [$item, , $sauce] = $this->makeItemWithSupplements();

        // 2× la sauce supplémentaire payante (0,60€ l'unité) : couvre aussi le
        // multiplicateur de quantité sur un extra (`item_extras[].quantity`).
        $out = $this->calc([$this->line($item->id, 1, [], [['id' => $sauce->id, 'quantity' => 2]])]);

        $this->assertSame(1.20, $out->lines[0]->extraTotal);
        $this->assertSame(12.00, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(2.00, $out->lines[0]->taxAmount);
        $this->assertSame(12.00, $out->total);
    }

    /* -----------------------------------------------------------------
     * 4) Article COMPOSÉ (wizard) — steps "Viande" (obligatoire, item_attribute)
     *    et "Sauce" (optionnelle, extra_group, jusqu'à 2, allow_repeat).
     * ---------------------------------------------------------------*/

    public function test_article_wizard_minimum_requis_tout_gratuit(): void
    {
        $w = $this->makeItemWithWizard();

        // Viande obligatoire (min=1,max=1) → Poulet (0€). Sauce optionnelle
        // (min=0) → aucune sélectionnée. C'est la composition la moins chère
        // possible qui reste valide vis-à-vis des contraintes du profil publié.
        $out = $this->calc([$this->line($w['item']->id, 1, [['id' => $w['poulet']->id]])]);

        $this->assertSame(7.70, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(0.70, $out->lines[0]->taxAmount);
        $this->assertSame(7.70, $out->total);
    }

    public function test_article_wizard_option_gratuite_supplementaire(): void
    {
        $w = $this->makeItemWithWizard();

        // Viande = Poulet (0€) + Sauce = Algérienne (0€, gratuite mais choisie).
        $out = $this->calc([$this->line(
            $w['item']->id,
            1,
            [['id' => $w['poulet']->id]],
            [['id' => $w['algerienne']->id]]
        )]);

        $this->assertSame(0.0, $out->lines[0]->variationTotal);
        $this->assertSame(0.0, $out->lines[0]->extraTotal);
        $this->assertSame(7.70, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(0.70, $out->lines[0]->taxAmount);
        $this->assertSame(7.70, $out->total);
    }

    public function test_article_wizard_option_payante(): void
    {
        $w = $this->makeItemWithWizard();

        // Viande = Bœuf (+1,10€, payante) + Sauce = Fromagère (+0,55€, payante)
        // et Algérienne (0€, gratuite) — 2 sauces sur un step max_select=2,
        // allow_repeat=true (mais ids distincts ici).
        $out = $this->calc([$this->line(
            $w['item']->id,
            1,
            [['id' => $w['boeuf']->id]],
            [['id' => $w['fromagere']->id], ['id' => $w['algerienne']->id]]
        )]);

        $this->assertSame(1.10, $out->lines[0]->variationTotal);
        $this->assertSame(0.55, $out->lines[0]->extraTotal);
        $this->assertSame(9.35, $out->lines[0]->lineSubtotalExTax);
        $this->assertSame(0.85, $out->lines[0]->taxAmount);
        $this->assertSame(9.35, $out->total);
    }

    /* -----------------------------------------------------------------
     * 5) Devis complet — panier mixte, les 4 articles, options payantes,
     *    deux taux de TVA différents. Fige aussi l'agrégation order-level
     *    (subtotal / totalTax / total), pas seulement les lignes.
     * ---------------------------------------------------------------*/

    public function test_devis_complet_panier_mixte_quatre_articles(): void
    {
        $simple = $this->makeItem('GOAL-ONB03 Tacos Simple', 8.80, $this->tax10);
        [$variantes, , $maxi] = $this->makeItemWithVariantes();
        [$supplements, , $sauceSuppl] = $this->makeItemWithSupplements();
        $w = $this->makeItemWithWizard();

        $out = $this->calc([
            $this->line($simple->id, 1),
            $this->line($variantes->id, 1, [['id' => $maxi->id]]),
            $this->line($supplements->id, 1, [], [['id' => $sauceSuppl->id, 'quantity' => 2]]),
            $this->line($w['item']->id, 1, [['id' => $w['boeuf']->id]], [['id' => $w['fromagere']->id]]),
        ]);

        $this->assertCount(4, $out->lines);
        // 8.80 + 8.65 + 12.00 + (7.70 + 1.10 + 0.55 = 9.35) = 38.80
        $this->assertSame(38.80, $out->subtotal);
        // 0.80 + 0.79 + 2.00 + 0.85 = 4.44
        $this->assertSame(4.44, $out->totalTax);
        // Mode TTC : total = somme des lignes TTC (la taxe n'est PAS rajoutée).
        $this->assertSame(38.80, $out->total);
    }

    /* -----------------------------------------------------------------
     * Fabriques (factories) — catalogue représentatif GOAL-ONB03
     * ---------------------------------------------------------------*/

    private function makeItem(string $name, float $price, Tax $tax): Item
    {
        // [PIÈGE vérifié 2026-08-26] `tax_id` est désormais OBLIGATOIRE sur les
        // articles (ItemRequest). Un article créé sans taxe se voit appliquer
        // 0% en silence par PricingService.php:240-243 — toujours rattacher
        // une taxe ici, jamais laisser le défaut de la factory faire foi.
        return Item::factory()->create([
            'name' => $name,
            'item_category_id' => $this->category->id,
            'tax_id' => $tax->id,
            'price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    /**
     * @return array{0: Item, 1: ItemVariation, 2: ItemVariation} [item, normale(gratuite), maxi(payante)]
     */
    private function makeItemWithVariantes(): array
    {
        $item = $this->makeItem('GOAL-ONB03 Sandwich Variantes', 7.15, $this->tax10);
        $attribute = ItemAttribute::factory()->create([
            'name' => 'GOAL-ONB03 Taille',
            'status' => Status::ACTIVE,
            'min_select' => 0,
            'max_select' => 1,
            'allow_repeat' => false,
        ]);
        $normale = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Normale',
            'price' => 0.00,
            'status' => Status::ACTIVE,
        ]);
        $maxi = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Maxi',
            'price' => 1.50,
            'status' => Status::ACTIVE,
        ]);

        return [$item, $normale, $maxi];
    }

    /**
     * @return array{0: Item, 1: ItemExtra, 2: ItemExtra} [item, crudites(gratuite), sauce(payante)]
     */
    private function makeItemWithSupplements(): array
    {
        $item = $this->makeItem('GOAL-ONB03 Assiette Suppléments', 10.80, $this->tax20);
        $crudites = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Crudités',
            'price' => 0.00,
            'status' => Status::ACTIVE,
            'group_label' => 'Options',
        ]);
        $sauceSuppl = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Sauce Supplémentaire',
            'price' => 0.60,
            'status' => Status::ACTIVE,
            'group_label' => 'Options',
        ]);

        return [$item, $crudites, $sauceSuppl];
    }

    /**
     * Article composé : profil wizard publié avec deux étapes (Viande
     * obligatoire / Sauce optionnelle jusqu'à 2), pour prouver que le composer
     * ne change RIEN au calcul de prix aujourd'hui (il ne fait que valider
     * les contraintes min/max/allow_repeat/disponibilité — cf. en-tête de
     * fichier). Retourne un tableau associatif pour lisibilité aux call sites.
     *
     * @return array{item: Item, poulet: ItemVariation, boeuf: ItemVariation, algerienne: ItemExtra, fromagere: ItemExtra}
     */
    private function makeItemWithWizard(): array
    {
        $item = $this->makeItem('GOAL-ONB03 Tacos Wizard', 7.70, $this->tax10);

        $attribute = ItemAttribute::factory()->create([
            'name' => 'GOAL-ONB03 Viande',
            'status' => Status::ACTIVE,
            'min_select' => 0,
            'max_select' => 1,
            'allow_repeat' => false,
        ]);
        $poulet = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Poulet',
            'price' => 0.00,
            'status' => Status::ACTIVE,
        ]);
        $boeuf = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Bœuf',
            'price' => 1.10,
            'status' => Status::ACTIVE,
        ]);

        $algerienne = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Algérienne',
            'price' => 0.00,
            'status' => Status::ACTIVE,
            'group_label' => 'Sauces',
        ]);
        $fromagere = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Fromagère',
            'price' => 0.55,
            'status' => Status::ACTIVE,
            'group_label' => 'Sauces',
        ]);

        $profile = ItemWizardProfile::query()->create([
            'item_id' => $item->id,
            'template' => 'custom',
            'version' => 1,
            'is_published' => true,
            'published_at' => now(),
            'branch_id_scope' => null,
        ]);
        $profile->steps()->create([
            'step_key' => 'viande',
            'label' => 'Viande',
            'source_type' => 'item_attribute',
            'source_ref' => (string) $attribute->id,
            'min_select' => 1,
            'max_select' => 1,
            'allow_repeat' => false,
            'visible_on' => ['pos', 'kiosk', 'web'],
            'stockable_choices' => false,
            'position' => 0,
            'is_active' => true,
        ]);
        $profile->steps()->create([
            'step_key' => 'sauce',
            'label' => 'Sauce',
            'source_type' => 'extra_group',
            'source_ref' => 'Sauces',
            'min_select' => 0,
            'max_select' => 2,
            'allow_repeat' => true,
            'visible_on' => ['pos', 'kiosk', 'web'],
            'stockable_choices' => false,
            'position' => 1,
            'is_active' => true,
        ]);

        return [
            'item' => $item,
            'poulet' => $poulet,
            'boeuf' => $boeuf,
            'algerienne' => $algerienne,
            'fromagere' => $fromagere,
        ];
    }

    /**
     * @param  array<int, array{id:int, quantity?:int}>  $variations
     * @param  array<int, array{id:int, quantity?:int}>  $extras
     */
    private function line(int $itemId, int $quantity, array $variations = [], array $extras = []): object
    {
        return (object) [
            'item_id' => $itemId,
            'quantity' => $quantity,
            'item_variations' => array_map(fn (array $v) => (object) $v, $variations),
            'item_extras' => array_map(fn (array $e) => (object) $e, $extras),
            'item_addons' => [],
            'instruction' => null,
        ];
    }

    /**
     * Devis (preview) — jamais une commande réelle : `orderId=0`. Surface
     * 'pos' pour figer les totaux ARRONDIS (comme la caisse Le Cayenne les
     * affiche), cohérent avec `PricingRequest::forPos()` déjà utilisé par
     * les autres suites `tests/Feature/Services/Pricing/*`.
     */
    private function calc(array $requestItems): \App\Services\Pricing\PricingResult
    {
        $req = PricingRequest::forPos(
            0,
            $this->branch->id,
            $requestItems,
            0,
            0,
            0.0,
            0.0
        );

        return $this->service->calculateOrder($req, $this->couponService);
    }
}
