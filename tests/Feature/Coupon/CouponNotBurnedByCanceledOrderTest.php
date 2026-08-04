<?php

namespace Tests\Feature\Coupon;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderCoupon;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [P1-D / P1-7 RED-PAIEMENT 2026-08-04] Un coupon 1-usage NE DOIT PAS être « brûlé » par une
 * commande ANNULÉE. La ligne order_coupons est insérée à la création de la commande, avant
 * paiement ; depuis l'auto-cancel des paiements carte échoués/annulés (webhook), une tentative
 * abandonnée consommait le quota → le client ne pouvait plus recommander (422). Le comptage
 * d'usage (limit_per_user + max_uses_global) doit exclure les commandes terminales annulées.
 */
class CouponNotBurnedByCanceledOrderTest extends TestCase
{
    use RefreshDatabase;

    private function coupon(int $perUser = 1, int $maxGlobal = 0): Coupon
    {
        return Coupon::create([
            'name' => 'OneUse', 'description' => 'x', 'code' => 'ONE'.uniqid(),
            'discount' => 10, 'discount_type' => DiscountType::PERCENTAGE, 'status' => Status::ACTIVE,
            'start_date' => null, 'end_date' => null, 'valid_days_of_week' => null,
            'surfaces' => null, 'branch_scope' => null, 'max_uses_global' => $maxGlobal,
            'usage_count' => 0, 'minimum_order' => 0, 'maximum_discount' => 0, 'limit_per_user' => $perUser,
        ]);
    }

    private function orderCouponRow(Coupon $c, int $userId, int $status): void
    {
        $order = Order::factory()->create(['status' => $status]);
        OrderCoupon::create(['order_id' => $order->id, 'coupon_id' => $c->id, 'user_id' => $userId, 'discount' => 1.0]);
    }

    /** @dataProvider terminalStatuses */
    public function test_canceled_order_does_not_burn_a_one_use_coupon(int $terminalStatus): void
    {
        $c = $this->coupon(perUser: 1);
        $u = User::factory()->create();

        // La 1ère tentative a été ANNULÉE (paiement carte échoué/abandonné).
        $this->orderCouponRow($c, $u->id, $terminalStatus);

        // Le client DOIT pouvoir recommander avec le même coupon (pas de 422).
        $resolved = $this->app->make(CouponService::class)->resolveCouponById($c->id, 20.0, $u->id, 1, 'web');
        $this->assertNotNull($resolved, "coupon utilisable après une commande $terminalStatus (jamais servi)");
    }

    public static function terminalStatuses(): array
    {
        return ['CANCELED' => [OrderStatus::CANCELED], 'REJECTED' => [OrderStatus::REJECTED], 'RETURNED' => [OrderStatus::RETURNED]];
    }

    /** Contrôle : une commande VIVANTE (non terminale) consomme bien le quota (comportement inchangé). */
    public function test_live_order_still_consumes_the_coupon(): void
    {
        $c = $this->coupon(perUser: 1);
        $u = User::factory()->create();
        $this->orderCouponRow($c, $u->id, OrderStatus::ACCEPT);

        $this->expectException(\Exception::class);
        $this->app->make(CouponService::class)->resolveCouponById($c->id, 20.0, $u->id, 1, 'web');
    }

    /** Cap global : les tentatives annulées ne consomment pas la capacité globale non plus. */
    public function test_canceled_orders_do_not_exhaust_global_cap(): void
    {
        $c = $this->coupon(perUser: 0, maxGlobal: 2);
        $a = User::factory()->create();
        $b = User::factory()->create();
        // 2 tentatives annulées ne doivent PAS épuiser un cap global de 2.
        $this->orderCouponRow($c, $a->id, OrderStatus::CANCELED);
        $this->orderCouponRow($c, $b->id, OrderStatus::CANCELED);

        $resolved = $this->app->make(CouponService::class)->resolveCouponById($c->id, 20.0, $a->id, 1, 'web');
        $this->assertNotNull($resolved, 'cap global non épuisé par des tentatives annulées');
    }
}
