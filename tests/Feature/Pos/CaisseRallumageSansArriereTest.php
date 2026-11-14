<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Pilotage\InterrupteurService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [REVUE ADVERSE 2026-10-19 · P3] Rallumer l'impression caisse ne doit pas déverser les 30 dernières
 * minutes d'un coup : c'est le symptôme d'origine (du papier qu'on n'attend pas).
 */
class CaisseRallumageSansArriereTest extends TestCase
{
    use RefreshDatabase;

    public function test_rallumee_la_caisse_ne_sert_que_les_commandes_arrivees_apres(): void
    {
        $this->seedSpatieRoles();
        $branch = Branch::factory()->create();
        $caissier = User::factory()->create(['branch_id' => $branch->id]);
        $caissier->assignRole('POS Operator');

        $fabriquer = fn (string $creeLe) => Order::factory()->create([
            'branch_id' => $branch->id, 'source_surface' => 'kiosk', 'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::PREPARING, 'payment_status' => PaymentStatus::PAID,
            'payment_method' => PaymentGateway::CARD, 'kitchen_ticket_printed_at' => null, 'created_at' => $creeLe,
        ]);
        $ancienne = $fabriquer(now()->subMinutes(10)->toDateTimeString());

        app(InterrupteurService::class)->regler('impression_caisse_auto', true);
        $apres = $fabriquer(now()->addSecond()->toDateTimeString());

        $res = $this->actingAs($caissier, 'sanctum')->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/kitchen-tickets/pending', ['destination' => 'counter']);
        $ids = collect($res->assertOk()->json('orders'))->pluck('id')->all();

        $this->assertNotContains($ancienne->id, $ids, 'l\'arriéré d\'avant l\'allumage ne doit JAMAIS sortir');
        $this->assertContains($apres->id, $ids, 'une commande arrivée après l\'allumage sort bien');
    }
}
