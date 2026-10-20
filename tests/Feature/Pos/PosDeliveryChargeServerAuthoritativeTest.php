<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Http\Requests\PosOrderRequest;
use App\Exceptions\Delivery\OutsideDeliveryZoneException;
use App\Models\Address;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [S2-02 / P2-f — REGISTRE_FINAL goal-intelligence-2026-07-18]
 * [LIVRAISON 2026-10-20 — réécrit pour le barème propriétaire]
 *
 * Invariant : le `delivery_charge` d'une DELIVERY POS est TOUJOURS une valeur SERVEUR autoritative,
 * dérivée de l'adresse ENREGISTRÉE du client de la commande (`customer_id` + `address_id`, géocodée
 * serveur : coordonnées + commune), jamais de la valeur ni de la distance envoyées par le navigateur
 * (« prix 100% backend », CLAUDE.md §8). Sans adresse/client exploitable, le montant est neutralisé à 0
 * (la validation `address_id` refuse ensuite) — plus aucun repli silencieux sur un forfait.
 *
 * La même garde (prepareForValidation) alimente le commit ; PosController::normalizePosRuntimePayload
 * en est le miroir exact pour /pos/quote (intents identiques → pas de 401 « quote intent mismatch »).
 * Géométrie : +0,054° de latitude ≈ 6,0 km (palier ≤ 8 km = 5,90 €).
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

    private function branchAt(float $lat = 48.8566, float $lng = 2.3522): Branch
    {
        return Branch::factory()->create(['latitude' => (string) $lat, 'longitude' => (string) $lng]);
    }

    private function savedAddress(Branch $branch, float $lat, float $lng, ?string $city, ?string $postal): array
    {
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $address = Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Domicile',
            'address' => '10 Rue de Test',
            'latitude' => (string) $lat,
            'longitude' => (string) $lng,
            'city' => $city,
            'postal_code' => $postal,
            'geocode_status' => 'OK',
        ]);

        return [$customer, $address];
    }

    /**
     * (a) DELIVERY + `delivery_charge` CLIENT forgé + SANS adresse enregistrée → frais neutralisés à 0,
     *     jamais la valeur client (0 / négatif / gonflé), jamais une distance client.
     *
     * @test
     *
     * @dataProvider forgedClientChargeProvider
     */
    public function delivery_without_saved_address_neutralises_client_charge(mixed $forged): void
    {
        $branch = $this->branchAt();

        $request = $this->prepared([
            'order_type' => OrderType::DELIVERY,
            'branch_id' => $branch->id,
            'customer_id' => 7,
            'delivery_charge' => $forged,   // valeur CLIENT à ignorer
            'delivery_distance_km' => 2.0,  // distance CLIENT à ignorer aussi
            // pas d'address_id
        ]);

        $this->assertEqualsWithDelta(0.0, (float) $request->input('delivery_charge'), 0.001,
            'Sans adresse enregistrée, aucun montant client ne doit survivre.');
        $this->assertEqualsWithDelta(0.0, (float) $request->input('delivery_distance_km'), 0.001,
            'La distance client ne doit pas survivre sans adresse enregistrée.');
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
     * (a-bis) adresse fournie mais SANS client de commande (`customer_id`) : l'adresse n'est pas
     *          vérifiable (propriété) → neutralisé à 0, jamais la valeur client.
     *
     * @test
     */
    public function delivery_with_address_but_without_customer_neutralises_client_charge(): void
    {
        $branch = $this->branchAt();
        [, $address] = $this->savedAddress($branch, 48.9106, 2.3522, 'Oignies', '62590');

        $request = $this->prepared([
            'order_type' => OrderType::DELIVERY,
            'branch_id' => $branch->id,
            'address_id' => $address->id,
            'delivery_charge' => 0, // client forge 0 → ignoré
        ]);

        $this->assertEqualsWithDelta(0.0, (float) $request->input('delivery_charge'), 0.001);
    }

    /**
     * (b) DELIVERY + adresse enregistrée du client → frais et distance dérivés du SERVEUR ;
     *     charge et distance forgées par le navigateur sont écrasées.
     *
     * @test
     */
    public function delivery_with_saved_address_recomputes_from_server_geometry(): void
    {
        $branch = $this->branchAt();
        [$customer, $address] = $this->savedAddress($branch, 48.9106, 2.3522, 'Oignies', '62590'); // ≈ 6 km

        $request = $this->prepared([
            'order_type' => OrderType::DELIVERY,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'address_id' => $address->id,
            'delivery_distance_km' => 0.1, // forgé
            'delivery_charge' => 999,      // forgé
        ]);

        $this->assertEqualsWithDelta(5.9, (float) $request->input('delivery_charge'), 0.001,
            'Le fee est recalculé par le barème serveur depuis l\'adresse enregistrée.');
        $this->assertEqualsWithDelta(6.0, (float) $request->input('delivery_distance_km'), 0.05,
            'La distance est mesurée par le serveur (haversine), pas celle du client.');
    }

    /** @test — Hénin-Beaumont : forfait 3,90 € quelle que soit la distance. */
    public function delivery_to_home_city_is_flat_3_90(): void
    {
        $branch = $this->branchAt();
        [$customer, $address] = $this->savedAddress($branch, 48.9106, 2.3522, 'Hénin-Beaumont', '62110');

        $request = $this->prepared([
            'order_type' => OrderType::DELIVERY,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'address_id' => $address->id,
            'delivery_charge' => 999,
        ]);

        $this->assertEqualsWithDelta(3.9, (float) $request->input('delivery_charge'), 0.001);
    }

    /** @test — au-delà de 10 km hors Hénin-Beaumont : refus 422 OUTSIDE_DELIVERY_ZONE (Uber Eats). */
    public function delivery_beyond_ten_km_is_refused(): void
    {
        $branch = $this->branchAt();
        [$customer, $address] = $this->savedAddress($branch, 48.9566, 2.3522, 'Lens', '62300'); // ≈ 11 km

        try {
            $this->prepared([
                'order_type' => OrderType::DELIVERY,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'address_id' => $address->id,
                'delivery_charge' => 0,
            ]);
            $this->fail('Une adresse à plus de 10 km doit être refusée.');
        } catch (OutsideDeliveryZoneException $e) {
            $this->assertTrue($e->suggestUberEats);
        }
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
