<?php

namespace Tests\Feature\Frontend;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [WEB-TOTAL-GUARD 2026-07-19] Défense-en-profondeur backend (non-frozen) contre le
 * « drop de prix » du front web standalone.
 *
 * Racine prouvée (reports/goal-drop-prix-2026-07-19/DIAG_WEB_BORNE.md) : le web chiffre
 * des options côté client (« 12 € »), puis `api.js resolveLine` en OMET silencieusement
 * au submit → le payload arrive incomplet → PricingService (SSOT) scelle un total
 * INFÉRIEUR (10 €) sans aucune erreur. Le web n'a pas le seal borne (OrderQuoteService,
 * kiosk-only).
 *
 * La garde : `POST /api/frontend/order` accepte un champ OPTIONNEL `expected_total`
 * (total attendu déclaré par le client). Il ne SERT JAMAIS à facturer — le serveur
 * calcule toujours son propre total via PricingService SSOT. S'il est fourni ET que
 * |total_serveur − expected_total| > 0.01 → 422 (commande jamais scellée). Absent →
 * comportement inchangé (rétro-compat, additif).
 *
 * Utilisateur = WEB (branch_id, PAS de KioskMachine) → aucun seal borne touché.
 */
class WebOrderExpectedTotalGuardTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $webUser;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        config(['app.api_key' => '123456']);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_takeaway' => 5,
            'order_setup_delivery' => 5,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'Guard Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue garde',
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Menus',
            'slug' => 'menus-guard',
            'status' => 5,
        ]);

        // Prix DB = 10,00 € → total serveur SSOT = 10,00 € (aucun tax_id → TVA 0, mode TTC).
        $this->item = Item::forceCreate([
            'name' => 'Galette Garde',
            'slug' => 'galette-garde',
            'price' => 10.00,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);

        $this->webUser = User::forceCreate([
            'name' => 'Web Order User',
            'email' => 'web-guard@test.local',
            'username' => 'web_guard',
            'phone' => '0600000020',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => Status::ACTIVE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'coupon_id' => null,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postOrder(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this
            ->actingAs($this->webUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $payload);
    }

    /**
     * (c) Rétro-compat : sans `expected_total`, comportement inchangé (accepté).
     */
    public function test_absent_expected_total_is_accepted_retrocompat(): void
    {
        $resp = $this->postOrder($this->basePayload());

        $resp->assertStatus(201);
        $this->assertSame(1, FrontendOrder::count());
        $this->assertEqualsWithDelta(10.00, (float) FrontendOrder::first()->total, 0.001);
    }

    /**
     * (a) `expected_total` == total serveur → accepté 200.
     */
    public function test_matching_expected_total_is_accepted(): void
    {
        $resp = $this->postOrder($this->basePayload(['expected_total' => 10.00]));

        $resp->assertStatus(201);
        $this->assertSame(1, FrontendOrder::count());
        $this->assertEqualsWithDelta(10.00, (float) FrontendOrder::first()->total, 0.001);
    }

    /**
     * (b) `expected_total`=12 mais serveur calcule 10 (payload incomplet, option
     *     manquante) → 422 dur, AUCUNE commande créée.
     */
    public function test_mismatched_expected_total_is_rejected_and_no_order_created(): void
    {
        $resp = $this->postOrder($this->basePayload(['expected_total' => 12.00]));

        $resp->assertStatus(422);
        $this->assertSame(0, FrontendOrder::count(), 'Aucune commande ne doit être scellée sur divergence de total.');
    }

    /**
     * (d) Le serveur facture TOUJOURS son propre total (SSOT PricingService), jamais
     *     `expected_total` ni un `total`/`subtotal` forgé par le client.
     */
    public function test_server_always_bills_its_own_ssot_total_not_client_values(): void
    {
        // expected_total correct (sinon la garde rejette), mais total/subtotal FORGÉS à 999 :
        // le montant persisté doit rester le total serveur (10,00 depuis le prix DB), prouvant
        // que ni le champ forgé ni expected_total ne pilotent la facturation.
        $resp = $this->postOrder($this->basePayload([
            'expected_total' => 10.00,
            'total' => 999.00,
            'subtotal' => 999.00,
        ]));

        $resp->assertStatus(201);
        $order = FrontendOrder::first();
        $this->assertEqualsWithDelta(10.00, (float) $order->total, 0.001, 'total facturé = SSOT (10), pas la valeur forgée (999).');
        $this->assertEqualsWithDelta(10.00, (float) $order->subtotal, 0.001, 'subtotal = SSOT (prix DB), pas la valeur forgée.');
    }
}
