<?php

namespace Tests\Feature\Promo;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderCoupon;
use App\Models\User;
use App\Services\Promo\PromoFlyerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [FLYER PROMO 2026-08-07] LE test qui manquait.
 *
 * Tous les tests écrits jusqu'ici s'arrêtaient à la CRÉATION du code (colonnes
 * du coupon, pré-contrôle du site). Aucun ne PASSAIT une commande avec le code.
 * Un audit adversarial a montré que c'est précisément là que tout se jouait :
 * un coupon portant une restriction `surfaces` est refusé au COMMIT parce que
 * `PricingService` (zone gelée) résout le coupon sans transmettre la surface ni
 * la branche, et que `Coupon::isUsableNow()` échoue en mode FERMÉ quand la
 * surface vaut null — comportement connu, documenté par
 * `CouponSurfaceEnforcedAtCommitTest`.
 *
 * Conséquence concrète si on ne le corrige pas : le client scanne le QR, voit
 * « −2,50 € appliqué », clique sur Commander, et sa commande est REFUSÉE. Le
 * pire des scénarios : promettre une remise puis la retirer au dernier clic —
 * exactement ce que la fonctionnalité prétendait éviter.
 *
 * Ce test est la garantie de bout en bout : un ticket imprimé DOIT pouvoir être
 * utilisé sur le site.
 */
class PromoFlyerRedeemableTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Item $item;
    private User $webUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        config([
            'app.api_key'              => '123456',
            'pos.coupon_codes_enabled' => true,
        ]);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_delivery'              => 5,
            'order_setup_takeaway'              => 5,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'Flyer Branch', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75000', 'address' => '20 rue remise', 'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Flyer Menus', 'slug' => 'flyer-menus', 'status' => Status::ACTIVE,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'Flyer Menu', 'slug' => 'flyer-menu', 'price' => 20.00,
            'status' => Status::ACTIVE, 'item_category_id' => $category->id,
        ]);

        $this->webUser = User::forceCreate([
            'name' => 'Client Web', 'email' => 'flyer-web@test.local',
            'username' => 'flyer_web', 'phone' => '0600009911',
            'password' => bcrypt('password123'), 'branch_id' => $this->branch->id,
            'status' => Status::ACTIVE,
        ]);
    }

    private function postWebOrder(int $couponId)
    {
        return $this
            ->actingAs($this->webUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', [
                'branch_id'        => $this->branch->id,
                'subtotal'         => 20.00,
                'discount'         => 0,
                'delivery_charge'  => 0,
                'total'            => 20.00,
                'order_type'       => OrderType::TAKEAWAY,
                'is_advance_order' => Ask::NO,
                'source'           => Source::WEB,
                'payment_method'   => PaymentGateway::CASH_ON_DELIVERY,
                'coupon_id'        => $couponId,
                'items'            => json_encode([[
                    'item_id'         => $this->item->id,
                    'quantity'        => 1,
                    'item_variations' => [],
                    'item_extras'     => [],
                ]]),
            ]);
    }

    /**
     * LE test de bout en bout : un ticket imprimé doit être utilisable.
     */
    /** @test */
    public function test_a_printed_flyer_code_can_actually_be_used_on_a_web_order(): void
    {
        $flyer = app(PromoFlyerService::class)
            ->create('Camille', (int) $this->branch->id, null, 'test');

        $response = $this->postWebOrder((int) $flyer->coupon_id);

        $this->assertSame(
            201,
            $response->status(),
            "Le code du ticket doit etre utilisable sur le site. Reponse : " . $response->getContent()
        );

        $this->assertSame(
            1,
            OrderCoupon::where('coupon_id', $flyer->coupon_id)->count(),
            "L'utilisation du coupon doit etre enregistree."
        );

        $order = FrontendOrder::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(
            2.00,
            (float) $order->discount,
            0.01,
            'Un code -10% sur 20 EUR doit retirer 2,00 EUR.'
        );
    }

    /**
     * L'usage unique doit tenir sur le chemin RÉEL de commande, pas seulement
     * en théorie : le code est un cadeau nominatif.
     */
    /** @test */
    public function test_the_code_cannot_be_used_twice(): void
    {
        $flyer = app(PromoFlyerService::class)
            ->create('Camille', (int) $this->branch->id, null, 'test');

        $this->postWebOrder((int) $flyer->coupon_id)->assertStatus(201);

        $second = $this->postWebOrder((int) $flyer->coupon_id);

        $this->assertSame(
            422,
            $second->status(),
            'Un code a usage unique ne doit jamais passer deux fois. Reponse : ' . $second->getContent()
        );

        $this->assertSame(
            1,
            OrderCoupon::where('coupon_id', $flyer->coupon_id)->count(),
            'Une seule utilisation doit etre enregistree.'
        );
    }
}
