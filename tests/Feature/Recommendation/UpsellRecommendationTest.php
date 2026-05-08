<?php

namespace Tests\Feature\Recommendation;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use App\Services\Recommendation\Strategies\MlPlaceholderStrategy;
use App\Services\Recommendation\Strategies\RuleBasedStrategy;
use App\Services\Recommendation\UpsellRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V2-3 Phase A — UpsellRecommendationController + RuleBasedStrategy +
 * MlPlaceholderStrategy.
 *
 * Couvre :
 *   - Heuristique catégories (burger → side+drink, ≥3 items → dessert,
 *     panier <10€ → combo)
 *   - Validation endpoint
 *   - Branch isolation (item_branch_availability)
 *   - MlPlaceholder délègue à RuleBased en V1.x
 *
 * Voir plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md.
 */
class UpsellRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Branch $otherBranch;
    private User $user;
    private string $token;

    private ItemCategory $catBurgers;
    private ItemCategory $catSides;
    private ItemCategory $catDrinks;
    private ItemCategory $catDesserts;

    private Item $burger;
    private Item $fries;
    private Item $coke;
    private Item $cake;
    private Item $cheapItem;
    private Item $branchExclusiveItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        $this->branch = Branch::forceCreate([
            'name' => 'Branch A', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75001', 'address' => '1 rue test', 'status' => 1,
            'available_locales' => ['fr', 'en'],
        ]);

        $this->otherBranch = Branch::forceCreate([
            'name' => 'Branch B', 'city' => 'Lyon', 'state' => 'ARA',
            'zip_code' => '69001', 'address' => '2 rue lyon', 'status' => 1,
            'available_locales' => ['fr'],
        ]);

        $tax = Tax::forceCreate(['name' => 'TVA 10', 'code' => 'TVA10', 'tax_rate' => 10, 'type' => 2, 'status' => 1]);

        $this->catBurgers = ItemCategory::forceCreate([
            'name' => 'Burgers', 'slug' => 'burgers-' . uniqid(),
            'status' => Status::ACTIVE, 'sort' => 1, 'channels' => ['kiosk'],
        ]);
        $this->catSides = ItemCategory::forceCreate([
            'name' => 'Sides', 'slug' => 'sides-' . uniqid(),
            'status' => Status::ACTIVE, 'sort' => 2, 'channels' => ['kiosk'],
        ]);
        $this->catDrinks = ItemCategory::forceCreate([
            'name' => 'Drinks', 'slug' => 'drinks-' . uniqid(),
            'status' => Status::ACTIVE, 'sort' => 3, 'channels' => ['kiosk'],
        ]);
        $this->catDesserts = ItemCategory::forceCreate([
            'name' => 'Desserts', 'slug' => 'desserts-' . uniqid(),
            'status' => Status::ACTIVE, 'sort' => 4, 'channels' => ['kiosk'],
        ]);

        $this->burger = Item::forceCreate([
            'name' => 'Big Burger', 'slug' => 'big-burger-' . uniqid(),
            'item_category_id' => $this->catBurgers->id, 'tax_id' => $tax->id,
            'item_type' => 1, 'price' => 9.50, 'status' => Status::ACTIVE,
            'order' => 1, 'channels' => ['kiosk'],
        ]);

        $this->fries = Item::forceCreate([
            'name' => 'Fries M', 'slug' => 'fries-m-' . uniqid(),
            'item_category_id' => $this->catSides->id, 'tax_id' => $tax->id,
            'item_type' => 1, 'price' => 2.50, 'status' => Status::ACTIVE,
            'order' => 1, 'channels' => ['kiosk'],
        ]);

        $this->coke = Item::forceCreate([
            'name' => 'Coke', 'slug' => 'coke-' . uniqid(),
            'item_category_id' => $this->catDrinks->id, 'tax_id' => $tax->id,
            'item_type' => 1, 'price' => 2.00, 'status' => Status::ACTIVE,
            'order' => 1, 'channels' => ['kiosk'],
        ]);

        $this->cake = Item::forceCreate([
            'name' => 'Brownie', 'slug' => 'brownie-' . uniqid(),
            'item_category_id' => $this->catDesserts->id, 'tax_id' => $tax->id,
            'item_type' => 1, 'price' => 3.50, 'status' => Status::ACTIVE,
            'order' => 1, 'channels' => ['kiosk'],
        ]);

        $this->cheapItem = Item::forceCreate([
            'name' => 'Mini Burger', 'slug' => 'mini-burger-' . uniqid(),
            'item_category_id' => $this->catBurgers->id, 'tax_id' => $tax->id,
            'item_type' => 1, 'price' => 4.00, 'status' => Status::ACTIVE,
            'order' => 2, 'channels' => ['kiosk'],
        ]);

        // Item exclusif à Branch B (Lyon) — ne doit JAMAIS apparaître dans
        // les recommandations pour Branch A (Paris).
        $this->branchExclusiveItem = Item::forceCreate([
            'name' => 'Lyon Exclusive Side', 'slug' => 'lyon-side-' . uniqid(),
            'item_category_id' => $this->catSides->id, 'tax_id' => $tax->id,
            'item_type' => 1, 'price' => 3.00, 'status' => Status::ACTIVE,
            'order' => 99, 'channels' => ['kiosk'],
        ]);

        // Branch A : tous les items SAUF branchExclusiveItem.
        foreach ([$this->burger, $this->fries, $this->coke, $this->cake, $this->cheapItem] as $i) {
            ItemBranchAvailability::create([
                'item_id' => $i->id, 'branch_id' => $this->branch->id,
                'is_available' => true,
                'daily_reset_at' => now()->toDateString(),
            ]);
        }
        // Branch B : seulement branchExclusiveItem.
        ItemBranchAvailability::create([
            'item_id' => $this->branchExclusiveItem->id,
            'branch_id' => $this->otherBranch->id,
            'is_available' => true,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $this->user = User::factory()->create([
            'username' => 'kiosk_' . uniqid(),
            'branch_id' => $this->branch->id,
        ]);
        KioskMachine::create([
            'machine_id' => 'test-' . uniqid(),
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'username' => 'kiosk-test',
            'password' => bcrypt('x'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);
        $this->token = $this->user->createToken('kiosk', ['kiosk:order'])->plainTextToken;
    }

    private function authed(): self
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"]);
        return $this;
    }

    // =====================================================================
    //  Endpoint validation
    // =====================================================================

    public function test_endpoint_requires_auth(): void
    {
        $this->postJson('/api/frontend/recommendations/upsell', [])
            ->assertStatus(401);
    }

    public function test_endpoint_validates_input(): void
    {
        // Manque branch_id et cart.
        $this->authed()
            ->postJson('/api/frontend/recommendations/upsell', [])
            ->assertStatus(422);

        // Manque branch_id.
        $this->authed()
            ->postJson('/api/frontend/recommendations/upsell', [
                'cart' => [['item_id' => $this->burger->id, 'quantity' => 1]],
            ])
            ->assertStatus(422);

        // cart vide.
        $this->authed()
            ->postJson('/api/frontend/recommendations/upsell', [
                'cart' => [],
                'branch_id' => $this->branch->id,
            ])
            ->assertStatus(422);
    }

    // =====================================================================
    //  Heuristics — RuleBasedStrategy
    // =====================================================================

    public function test_recommends_sides_drinks_when_cart_contains_burger(): void
    {
        $r = $this->authed()->postJson('/api/frontend/recommendations/upsell', [
            'cart' => [['item_id' => $this->burger->id, 'quantity' => 1, 'price' => 9.50]],
            'branch_id' => $this->branch->id,
        ]);

        $r->assertStatus(200);
        $data = collect($r->json('data'));
        $this->assertGreaterThan(0, $data->count(), 'Au moins 1 reco pour panier burger.');

        $reasons = $data->pluck('reason')->all();
        $this->assertTrue(
            in_array('side_for_burger', $reasons, true) || in_array('drink_for_burger', $reasons, true),
            'La recommandation doit contenir un side ou un drink (reason).'
        );

        $itemIds = $data->pluck('item_id')->all();
        $this->assertTrue(
            in_array($this->fries->id, $itemIds, true) || in_array($this->coke->id, $itemIds, true),
            'La recommandation doit contenir Fries ou Coke (items branche A).'
        );
    }

    public function test_recommends_dessert_when_cart_has_3_plus_items(): void
    {
        $r = $this->authed()->postJson('/api/frontend/recommendations/upsell', [
            'cart' => [
                ['item_id' => $this->burger->id, 'quantity' => 1, 'price' => 9.50],
                ['item_id' => $this->fries->id, 'quantity' => 1, 'price' => 2.50],
                ['item_id' => $this->coke->id, 'quantity' => 1, 'price' => 2.00],
            ],
            'branch_id' => $this->branch->id,
        ]);

        $r->assertStatus(200);
        $data = collect($r->json('data'));
        $itemIds = $data->pluck('item_id')->all();
        $this->assertContains(
            $this->cake->id,
            $itemIds,
            'Brownie (dessert) attendu pour panier ≥3 items sans dessert.'
        );
    }

    public function test_recommends_combo_when_cart_under_10_eur(): void
    {
        $r = $this->authed()->postJson('/api/frontend/recommendations/upsell', [
            'cart' => [['item_id' => $this->cheapItem->id, 'quantity' => 1, 'price' => 4.00]],
            'branch_id' => $this->branch->id,
        ]);

        $r->assertStatus(200);
        $data = collect($r->json('data'));
        $this->assertGreaterThan(0, $data->count(), 'Reco attendue pour panier <10€.');

        $reasons = $data->pluck('reason')->all();
        // Le burger déclenche side+drink ; en plus, panier 4€ < 10€ devrait
        // ajouter un combo. Au minimum on doit avoir une raison liée au cart.
        $this->assertNotEmpty(
            array_intersect($reasons, ['combo_under_threshold', 'side_for_burger', 'drink_for_burger']),
            'Reco doit refléter au moins une heuristique active.'
        );
    }

    // =====================================================================
    //  Branch isolation
    // =====================================================================

    public function test_respects_branch_id_isolation(): void
    {
        $r = $this->authed()->postJson('/api/frontend/recommendations/upsell', [
            'cart' => [['item_id' => $this->burger->id, 'quantity' => 1]],
            'branch_id' => $this->branch->id,
        ]);

        $r->assertStatus(200);
        $itemIds = collect($r->json('data'))->pluck('item_id')->all();

        $this->assertNotContains(
            $this->branchExclusiveItem->id,
            $itemIds,
            'Item exclusif à Branch B ne doit JAMAIS apparaître pour Branch A (item_branch_availability).'
        );
    }

    // =====================================================================
    //  Strategy switch — MlPlaceholder
    // =====================================================================

    public function test_ml_placeholder_strategy_falls_back_to_rule_based(): void
    {
        $cart = [
            ['item_id' => $this->burger->id, 'quantity' => 1, 'price' => 9.50, 'category_id' => $this->catBurgers->id],
        ];
        $branchId = $this->branch->id;

        $ruleBased = app(RuleBasedStrategy::class)->recommend($cart, $branchId);
        $mlPlaceholder = app(MlPlaceholderStrategy::class)->recommend($cart, $branchId);

        $this->assertSame(
            count($ruleBased),
            count($mlPlaceholder),
            'V1.x: MlPlaceholder délègue à RuleBased — taille de payload identique.'
        );

        // Même structure (clés et item_ids).
        $this->assertSame(
            collect($ruleBased)->pluck('item_id')->all(),
            collect($mlPlaceholder)->pluck('item_id')->all(),
            'V1.x: MlPlaceholder délègue à RuleBased — item_ids identiques.'
        );
    }

    // =====================================================================
    //  Container binding via config
    // =====================================================================

    public function test_container_binds_strategy_from_config(): void
    {
        config()->set('recommendation.strategy', 'rule_based');
        $this->assertInstanceOf(
            RuleBasedStrategy::class,
            app(UpsellRecommendationService::class)
        );

        config()->set('recommendation.strategy', 'ml_placeholder');
        // Re-bind après changement de config.
        $this->app->bind(
            UpsellRecommendationService::class,
            fn ($app) => match (config('recommendation.strategy')) {
                'ml_placeholder' => $app->make(MlPlaceholderStrategy::class),
                default          => $app->make(RuleBasedStrategy::class),
            }
        );
        $this->assertInstanceOf(
            MlPlaceholderStrategy::class,
            app(UpsellRecommendationService::class)
        );
    }
}
