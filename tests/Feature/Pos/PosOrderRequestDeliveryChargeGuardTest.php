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

    /**
     * @test — [S2-02 / P2-f 2026-07-18 · LIVRAISON 2026-10-20] une vraie DELIVERY SANS adresse
     * enregistrée du client N'UTILISE PAS le delivery_charge CLIENT : la garde le neutralise à 0
     * (la validation `address_id` refusera ensuite), jamais la valeur du client. Cf.
     * PosDeliveryChargeServerAuthoritativeTest pour la couverture complète (adresse, barème, refus).
     */
    public function real_delivery_without_address_never_uses_client_value(): void
    {
        $request = $this->prepared([
            'order_type'      => OrderType::DELIVERY,
            'delivery_charge' => 4, // valeur CLIENT — doit être ignorée
            // pas de customer_id / address_id → aucun montant ne survit
        ]);

        $this->assertEqualsWithDelta(
            0.0,
            (float) $request->input('delivery_charge'),
            0.001,
            'Sans adresse enregistrée, une DELIVERY ne doit conserver aucun montant client.'
        );
        $this->assertNotSame(
            4,
            (int) $request->input('delivery_charge'),
            'La valeur CLIENT forgée ne doit jamais être conservée telle quelle (prix 100% backend).'
        );
    }
}
