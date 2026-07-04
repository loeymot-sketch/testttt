<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Http\Requests\PosOrderRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT Wave 2 2026-07-04 — durcissement anti-gonflage delivery_charge côté POS]
 *
 * Le service frère web/borne force `delivery_charge=0` pour toute commande NON-DELIVERY
 * (FrontendOrderService:280, contre un payload forgé order_type=TAKEAWAY + delivery_charge=99).
 * Le POS ne l'appliquait PAS : `delivery_charge` est `nullable` pour non-livraison, donc un
 * payload forgé OU une désync UI livraison→emporter gonflait le total (PricingService l'ajoute
 * au rawTotal). Ce test verrouille la parité de la garde dans PosOrderRequest::prepareForValidation.
 */
class PosOrderRequestDeliveryChargeGuardTest extends TestCase
{
    use RefreshDatabase;

    private function prepared(array $payload): PosOrderRequest
    {
        $request = PosOrderRequest::create('/api/admin/pos-order/store', 'POST', $payload);
        $request->setContainer(app());
        $m = new \ReflectionMethod($request, 'prepareForValidation');
        $m->setAccessible(true);
        $m->invoke($request);

        return $request;
    }

    /** @test — payload forgé : TAKEAWAY + delivery_charge=99 → neutralisé à 0. */
    public function takeaway_with_crafted_delivery_charge_is_zeroed(): void
    {
        $request = $this->prepared([
            'order_type'      => OrderType::TAKEAWAY,
            'delivery_charge' => 99,
        ]);

        $this->assertSame(
            0,
            (int) $request->input('delivery_charge'),
            'Un TAKEAWAY ne doit porter aucun delivery_charge (anti-gonflage).'
        );
    }

    /** @test — désync/distance fantôme : TAKEAWAY + delivery_distance_km ne calcule PAS de fee. */
    public function takeaway_with_distance_does_not_get_a_phantom_fee(): void
    {
        $request = $this->prepared([
            'order_type'           => OrderType::TAKEAWAY,
            'delivery_distance_km' => 8,
        ]);

        $this->assertSame(
            0,
            (int) $request->input('delivery_charge'),
            'Un TAKEAWAY portant une distance ne doit PAS recevoir de fee de livraison.'
        );
    }

    /** @test — non-régression : une vraie DELIVERY conserve son delivery_charge explicite. */
    public function real_delivery_preserves_its_delivery_charge(): void
    {
        $request = $this->prepared([
            'order_type'      => OrderType::DELIVERY,
            'delivery_charge' => 4,
            // pas de delivery_distance_km → pas de recalcul, la valeur explicite est conservée
        ]);

        $this->assertSame(
            4,
            (int) $request->input('delivery_charge'),
            'Une vraie livraison NE DOIT PAS être remise à 0 par la garde.'
        );
    }
}
