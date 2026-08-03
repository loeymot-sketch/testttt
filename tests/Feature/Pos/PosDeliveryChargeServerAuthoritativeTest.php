<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Http\Requests\PosOrderRequest;
use App\Models\Branch;
use App\Services\Delivery\DeliveryFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [S2-02 / P2-f — REGISTRE_FINAL goal-intelligence-2026-07-18]
 *
 * En POS DELIVERY SANS `delivery_distance_km`, l'ancien code laissait passer le
 * `delivery_charge` CLIENT : PosOrderRequest::prepareForValidation ne recalculait le
 * fee QUE lorsqu'une distance était fournie, sinon la valeur client persistait
 * (le store ne unset que total/subtotal/discount) jusqu'au moteur de pricing. Sous
 * le seuil de livraison offerte, un fee forgé (0, négatif ou gonflé) était facturé
 * tel quel → violation de « prix 100% backend » (CLAUDE.md §8).
 *
 * Ce test verrouille l'invariant : le `delivery_charge` d'une DELIVERY est TOUJOURS
 * une valeur SERVEUR autoritative — fee depuis la distance si fournie (flux POS
 * normal, PosComponent.vue:3911), sinon fee de config branche (fromDistanceKm(0)) —
 * et jamais la valeur client. La même garde (prepareForValidation) alimente le
 * commit ; PosController::normalizePosRuntimePayload en est le miroir exact pour
 * l'endpoint /pos/quote (intents identiques → pas de 401 « quote intent mismatch »).
 */
class PosDeliveryChargeServerAuthoritativeTest extends TestCase
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

    /**
     * (a) DELIVERY + `delivery_charge` CLIENT forgé + SANS distance → le fee SERVEUR
     *     (config branche) est utilisé, jamais la valeur client (0 / négatif / gonflé).
     *
     * @test
     *
     * @dataProvider forgedClientChargeProvider
     */
    public function delivery_without_distance_uses_server_fee_not_client(mixed $forged): void
    {
        // Branche avec config fee explicite → valeur serveur déterministe : base=4, mini=4,
        // free_km=5 → fromDistanceKm(0) = max(4, 4 + 1*0) = 4.00.
        $branch = Branch::factory()->create([
            'delivery_fee_base' => 4.00,
            'delivery_fee_per_km' => 1.00,
            'delivery_fee_minimum' => 4.00,
            'delivery_fee_free_km' => 5.00,
        ]);

        $expectedServerFee = app(DeliveryFeeService::class)->fromDistanceKm(0, $branch); // 4.00

        $request = $this->prepared([
            'order_type' => OrderType::DELIVERY,
            'branch_id' => $branch->id,
            'address_id' => 123,          // adresse quelconque : ignorée, le fee reste config serveur
            'customer_id' => 7,
            'delivery_charge' => $forged, // valeur CLIENT à ignorer
            // pas de delivery_distance_km
        ]);

        $this->assertEqualsWithDelta(
            $expectedServerFee,
            (float) $request->input('delivery_charge'),
            0.001,
            'Sans distance, le delivery_charge doit venir de la config SERVEUR, pas du client.'
        );
        $this->assertNotEquals(
            (float) $forged,
            (float) $request->input('delivery_charge'),
            'La valeur CLIENT forgée ne doit jamais être facturée.'
        );
    }

    public static function forgedClientChargeProvider(): array
    {
        return [
            'client sends 0' => [0],
            'client sends negative' => [-50],
            'client inflates' => [999],
        ];
    }

    /**
     * (a-bis) SANS branche configurée ni distance → repli sur la formule legacy (5€),
     *          jamais la valeur client.
     *
     * @test
     */
    public function delivery_without_distance_or_branch_falls_back_to_legacy_fee(): void
    {
        $request = $this->prepared([
            'order_type' => OrderType::DELIVERY,
            'delivery_charge' => 0, // client forge 0 → doit être ignoré
            // ni branch_id, ni distance → repli legacy fromDistanceKm(0, null) = 5€
        ]);

        $legacyFee = app(DeliveryFeeService::class)->fromDistanceKm(0, null); // 5.0

        $this->assertEqualsWithDelta(
            $legacyFee,
            (float) $request->input('delivery_charge'),
            0.001,
            'Sans branche ni distance, le fee doit être le repli SERVEUR legacy, pas le 0 client.'
        );
        $this->assertGreaterThan(
            0.0,
            (float) $request->input('delivery_charge'),
            'Un fee serveur > 0 doit remplacer le 0 forgé par le client.'
        );
    }

    /**
     * (b) DELIVERY + distance → recalcul depuis la distance (comportement existant préservé).
     *
     * @test
     */
    public function delivery_with_distance_recomputes_from_distance(): void
    {
        $branch = Branch::factory()->create(); // pas de config → formule legacy
        $expected = app(DeliveryFeeService::class)->fromDistanceKm(5.01, $branch); // max(5, ceil(5.01/5)*5) = 10

        $request = $this->prepared([
            'order_type' => OrderType::DELIVERY,
            'branch_id' => $branch->id,
            'delivery_distance_km' => 5.01,
            'delivery_charge' => 999, // client ignoré
        ]);

        $this->assertEqualsWithDelta(
            $expected,
            (float) $request->input('delivery_charge'),
            0.001,
            'Avec distance, le fee est recalculé depuis la distance (comportement existant).'
        );
        $this->assertNotEquals(
            999.0,
            (float) $request->input('delivery_charge'),
            'La valeur client ne doit pas être conservée avec une distance fournie.'
        );
    }

    /**
     * (c) NON-DELIVERY → delivery_charge forcé à 0 (garde Wave 2 préservée).
     *
     * @test
     */
    public function non_delivery_forces_zero_delivery_charge(): void
    {
        $request = $this->prepared([
            'order_type' => OrderType::TAKEAWAY,
            'delivery_charge' => 99,
        ]);

        $this->assertSame(
            0,
            (int) $request->input('delivery_charge'),
            'Un TAKEAWAY ne doit porter aucun delivery_charge (anti-gonflage).'
        );
    }
}
