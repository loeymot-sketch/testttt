<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Events\OrderStatusChanged;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
use App\Models\Branch;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\Loyalty\PosRedemptionException;
use App\Services\Loyalty\PosRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [DÉCOUPLAGE FIDÉLITÉ 2026-07-18] Owner : « coupe les remises MAIS garde la
 * fidélité ». Le kill-switch UNIQUE `pos.manual_discount_enabled` coupait AUSSI
 * la fidélité (un seul chokepoint gatait remise manuelle + coupon + redeem
 * ensemble). Ce test verrouille le DÉCOUPLAGE :
 *
 *   (a) accrual (gain de points) + consultation du solde = TOUJOURS actifs même
 *       remises manuelles coupées (accrual n'applique aucune réduction → 0 risque
 *       fiscal) ;
 *   (b) les remises manuelles/coupon restent coupées — activer la fidélité ne les
 *       réactive pas (refus comportemental verrouillé par
 *       ManualDiscountDisabledV1SentinelTest, préservé) ;
 *   (c) le REDEEM de points est ré-ouvert via le flag DÉDIÉ `pos.loyalty_enabled`
 *       (défaut true), DÉCOUPLÉ de `pos.manual_discount_enabled`.
 *
 * DÉCISION F1 → REDEEM ON : F1 (netting TVA du Z sur base remisée) est FIXÉ +
 * prouvé par ZReportDiscountNettingTest (5/5, incl. close()+sign()+verifyChain()
 * sur un Z réellement remisé). Un ordre remisé fidélité (discount>0, total_tax
 * gross) signe donc un Z fiscalement CORRECT → le redeem est fiscalement sûr.
 */
class LoyaltyDecoupledFromManualDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro' => 10,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 50,
        ]);
    }

    /**
     * (a) Accrual — l'octroi de points fonctionne même quand les remises manuelles
     * sont coupées (l'accrual n'est gaté par AUCUN flag remise).
     */
    public function test_accrual_awards_points_even_when_manual_discount_disabled(): void
    {
        Config::set('pos.manual_discount_enabled', false);
        Config::set('pos.loyalty_enabled', true);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => Status::ACTIVE,
            'loyalty_code'   => 'ACCRUE01',
            'loyalty_points' => 0,
        ]);

        // Le listener lit order_amount ?? total pour un ordre POS ; la colonne
        // order_amount est absente du schéma de test → fallback sur total (10€).
        $order = Order::factory()->create([
            'branch_id'              => $branch->id,
            'user_id'                => $customer->id,
            'order_type'             => OrderType::POS,
            'total'                  => 10.00,
            'status'                 => OrderStatus::DELIVERED,
            'payment_status'         => PaymentStatus::PAID,
            'loyalty_points_awarded' => null,
        ]);

        (new AwardLoyaltyPointsOnDelivery())->handle(
            new OrderStatusChanged($order, OrderStatus::PREPARING, OrderStatus::DELIVERED)
        );

        $customer->refresh();
        // 10€ * 10 pts/€ = 100 pts crédités.
        $this->assertSame(100, (int) $customer->loyalty_points, 'Accrual doit fonctionner même remises manuelles coupées.');
        $this->assertSame(
            1,
            LoyaltyTransaction::where('user_id', $customer->id)->where('type', 'earn')->count(),
            'Un ledger earn doit être écrit.'
        );
    }

    /**
     * (a) Consultation du solde — l'endpoint /loyalty/check n'est gaté par aucun
     * flag remise ; le propriétaire du code lit son solde même remises coupées.
     */
    public function test_balance_lookup_works_even_when_manual_discount_disabled(): void
    {
        Config::set('pos.manual_discount_enabled', false);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => Status::ACTIVE,
            'loyalty_code'   => 'BAL00001',
            'loyalty_points' => 250,
        ]);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/frontend/loyalty/check', ['code' => 'BAL00001']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.points', 250);
    }

    /**
     * (c) Redeem DÉCOUPLÉ — avec les remises manuelles COUPÉES mais la fidélité
     * ACTIVE, le redeem POS (PosRedemptionService, post-create, ne passe pas par le
     * gate de création OrderService) réussit. F1 fixé → fiscalement sûr.
     */
    public function test_pos_loyalty_redeem_is_allowed_when_manual_discount_disabled(): void
    {
        Config::set('pos.manual_discount_enabled', false); // remises manuelles COUPÉES
        Config::set('pos.loyalty_enabled', true);          // fidélité ACTIVE

        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => Status::ACTIVE,
            'loyalty_code'   => 'REDEEM01',
            'loyalty_points' => 500,
        ]);
        $order = Order::factory()->create([
            'branch_id'       => $branch->id,
            'user_id'         => $customer->id,
            'order_type'      => OrderType::POS,
            'subtotal'        => 25.00,
            'discount'        => 0.00,
            'total_tax'       => 0.00,
            'delivery_charge' => 0.00,
            'total'           => 25.00,
            'status'          => OrderStatus::PENDING,
            'payment_status'  => PaymentStatus::UNPAID,
        ]);

        $result = app(PosRedemptionService::class)->applyToOrder($order, 100, 'REDEEM01', null);

        // 100 pts = 1€ → remise 1€, total 24€, solde 400.
        $this->assertEqualsWithDelta(1.00, (float) $result['discount_eur'], 0.01);
        $this->assertSame(400, (int) $result['balance_after']);

        $customer->refresh();
        $this->assertSame(400, (int) $customer->loyalty_points);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertEqualsWithDelta(1.00, (float) $fresh->discount, 0.01);
        $this->assertEqualsWithDelta(24.00, (float) $fresh->total, 0.01);
        $this->assertSame('REDEEM01', (string) $fresh->loyalty_customer_code);

        $this->assertSame(
            1,
            LoyaltyTransaction::where('user_id', $customer->id)->where('type', 'redeem')->count(),
            'Un ledger redeem doit être écrit.'
        );
    }

    /**
     * Kill-switch DÉDIÉ fidélité — loyalty_enabled=false refuse le redeem AVANT
     * toute mutation (LOYALTY_DISABLED), sans réactiver les remises manuelles.
     */
    public function test_pos_loyalty_redeem_is_refused_when_loyalty_disabled(): void
    {
        Config::set('pos.loyalty_enabled', false);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => Status::ACTIVE,
            'loyalty_code'   => 'REDEEM02',
            'loyalty_points' => 500,
        ]);
        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'user_id'        => $customer->id,
            'order_type'     => OrderType::POS,
            'subtotal'       => 25.00,
            'discount'       => 0.00,
            'total'          => 25.00,
            'status'         => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
        ]);

        try {
            app(PosRedemptionService::class)->applyToOrder($order, 100, 'REDEEM02', null);
            $this->fail('Le redeem doit être refusé quand la fidélité est désactivée.');
        } catch (PosRedemptionException $e) {
            $this->assertSame('LOYALTY_DISABLED', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }

        // Aucune mutation.
        $customer->refresh();
        $this->assertSame(500, (int) $customer->loyalty_points, 'Aucun point débité sur refus.');
        $this->assertSame(0, LoyaltyTransaction::where('type', 'redeem')->count());
    }

    /**
     * (b) Invariant de découplage — activer la fidélité ne réactive JAMAIS les
     * remises discrétionnaires. Le refus COMPORTEMENTAL manuel/coupon est
     * verrouillé par ManualDiscountDisabledV1SentinelTest (préservé).
     */
    public function test_enabling_loyalty_keeps_manual_discounts_cut(): void
    {
        Config::set('pos.manual_discount_enabled', false);
        Config::set('pos.loyalty_enabled', true);

        $this->assertFalse((bool) config('pos.manual_discount_enabled'), 'Le kill-switch remises manuelles reste engagé.');
        $this->assertTrue((bool) config('pos.loyalty_enabled'), 'La fidélité est active en parallèle.');

        // L'inverse aussi : couper la fidélité ne réactive pas les remises manuelles.
        Config::set('pos.loyalty_enabled', false);
        $this->assertFalse((bool) config('pos.manual_discount_enabled'), 'Couper la fidélité ne touche pas le flag remises manuelles.');
    }
}
