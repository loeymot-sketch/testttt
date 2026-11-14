<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M4-002] La caisse ne pouvait pas afficher l'heure de départ d'une livraison
 * (file « À encaisser », tracker) : ni OrderDetailsResource ni counter-collect/pending ne
 * portaient `delivery_slot_at`. On expose le départ de tournée (ISO 8601) et `prevue_le`
 * (même définition que le suivi client, OrderTrackingService) — pour les livraisons seulement.
 */
class OrderDetailsDepartLivraisonTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        Carbon::setTestNow(Carbon::parse('2026-10-22 17:10:00', config('app.timezone')));
        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enAttente(array $extra): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'source_surface' => 'pos',
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
        ], $extra));
    }

    private function carte(string $url, int $id): array
    {
        $res = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson($url);
        $res->assertOk();
        $card = collect($res->json('data'))->firstWhere('id', $id);
        $this->assertNotNull($card, "commande {$id} absente de {$url}");

        return $card;
    }

    /** @test */
    public function une_livraison_affectee_a_une_tournee_porte_son_depart_et_son_jour(): void
    {
        $depart = Carbon::parse('2026-10-23 20:30:00', config('app.timezone'));
        $livraison = $this->enAttente([
            'order_type' => OrderType::DELIVERY,
            'delivery_slot_at' => $depart,
            'scheduled_at' => null,
        ]);
        $emporter = $this->enAttente(['order_type' => OrderType::TAKEAWAY, 'delivery_slot_at' => null]);

        $l = $this->carte('/api/admin/pos/counter-collect/pending', $livraison->id);
        $this->assertNotNull($l['delivery_slot_at'] ?? null, 'le départ de tournée doit être exposé');
        $this->assertTrue(Carbon::parse($l['delivery_slot_at'])->equalTo($depart), 'ISO 8601 du départ');
        $this->assertSame('2026-10-23', $l['prevue_le'], 'départ de DEMAIN : le jour doit être dit');

        $e = $this->carte('/api/admin/pos/counter-collect/pending', $emporter->id);
        $this->assertArrayHasKey('delivery_slot_at', $e);
        $this->assertNull($e['delivery_slot_at']);
        $this->assertNull($e['prevue_le']);
    }

    /** @test */
    public function la_file_web_porte_aussi_le_depart_d_une_livraison(): void
    {
        $depart = Carbon::parse('2026-10-22 20:30:00', config('app.timezone'));
        $web = $this->enAttente([
            'source_surface' => 'delivery', 'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::PENDING, 'payment_status' => PaymentStatus::UNPAID,
            'delivery_slot_at' => $depart, 'scheduled_at' => null,
        ]);

        $c = $this->carte('/api/admin/pos/web-orders/pending', $web->id);
        $this->assertTrue(Carbon::parse($c['delivery_slot_at'])->equalTo($depart));
        $this->assertSame('2026-10-22', $c['prevue_le']);
    }
}
