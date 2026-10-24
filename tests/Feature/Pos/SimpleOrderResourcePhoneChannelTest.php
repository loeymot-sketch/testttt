<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Http\Resources\SimpleOrderResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [FIX-6 / A-012 · GOAL-CAISSE-VISION 2026-08-25] Le canal TÉLÉPHONE doit pouvoir être RAPPELÉ.
 *
 * Constat de la vague A du superviseur : sur une carte `source_surface='phone'`, aucun numéro,
 * aucun lien `tel:` — alors que `pos_customer_phone` était bien scellé sur la commande. La cause
 * était `SimpleOrderResource::displayCustomerPhone()`, qui n'autorisait le numéro que pour
 * `order_type == DELIVERY` ou `source_surface == 'web'`. Le canal dont la raison d'être est
 * « le client n'est PAS là » était le seul qu'on ne pouvait pas rappeler.
 *
 * MINIMISATION DES DONNÉES (Z9-P0-03 + décision owner 2026-07-31) — pourquoi `phone` entre :
 *   - le numéro d'une commande téléphone est SAISI par le caissier lui-même, à l'appel : il n'est
 *     pas extrait d'un compte client, il EST la commande ;
 *   - la finalité est la même que pour la livraison et le web — joindre un client ABSENT pour
 *     exécuter/confirmer la commande (rupture, retard, retrait) ;
 *   - la minimisation reste entière là où elle a un sens : borne et walk-in, client physiquement
 *     présent au comptoir → toujours `null`. C'est ce que ce test épingle des deux côtés.
 */
class SimpleOrderResourcePhoneChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function payloadFor(Order $order): array
    {
        $order->load(['orderItems', 'user', 'transaction']);

        return (new SimpleOrderResource($order))->resolve();
    }

    private function makeOrder(array $attrs): Order
    {
        $this->actingAs(User::factory()->create(['branch_id' => 0]));
        $branch = Branch::factory()->create();

        return Order::factory()->create(array_merge([
            'branch_id'      => $branch->id,
            'order_datetime' => now(),
        ], $attrs))->fresh();
    }

    /** @test */
    public function une_commande_telephone_expose_le_numero_saisi_par_le_caissier(): void
    {
        $order = $this->makeOrder([
            'order_type'         => OrderType::TAKEAWAY,
            'source_surface'     => 'phone',
            'pos_customer_name'  => 'Karim Bensalah',
            'pos_customer_phone' => '06 12 34 56 78',
        ]);

        $payload = $this->payloadFor($order);

        $this->assertSame(
            '06 12 34 56 78',
            $payload['customer_phone'],
            'Le canal téléphone doit être rappelable : le numéro est saisi par le caissier, pour cette commande.'
        );
        $this->assertSame('Karim Bensalah', $payload['customer_name']);
        $this->assertSame('phone', $payload['source_surface']);
    }

    /** @test */
    public function une_commande_telephone_en_livraison_reste_rappelable(): void
    {
        $order = $this->makeOrder([
            'order_type'         => OrderType::DELIVERY,
            'source_surface'     => 'phone',
            'pos_customer_phone' => '0700000001',
        ]);

        $this->assertSame('0700000001', $this->payloadFor($order)['customer_phone']);
    }

    /** @test */
    public function la_minimisation_tient_toujours_pour_la_borne_et_le_comptoir(): void
    {
        foreach (['kiosk', 'pos'] as $surface) {
            $order = $this->makeOrder([
                'order_type'         => OrderType::TAKEAWAY,
                'source_surface'     => $surface,
                'pos_customer_phone' => '0699999999',
            ]);

            $this->assertNull(
                $this->payloadFor($order)['customer_phone'],
                "Client physiquement présent ({$surface}) : le numéro NE doit pas voyager (Z9-P0-03)."
            );
        }
    }

    /** @test */
    public function un_canal_agregateur_ne_prete_jamais_le_numero_de_son_ancre_technique(): void
    {
        $ancre = User::factory()->create(['branch_id' => 0, 'phone' => '0000000042']);

        $order = $this->makeOrder([
            'order_type'         => OrderType::DELIVERY,
            'source_surface'     => 'uber_eats',
            'user_id'            => $ancre->id,
            'pos_customer_phone' => null,
        ]);

        $this->assertNull(
            $this->payloadFor($order)['customer_phone'],
            "Le numéro de l'ancre technique d'un agrégateur n'est le numéro de personne."
        );
    }
}
