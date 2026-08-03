<?php

namespace Tests\Feature\Kitchen;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Database\Factories\OrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [E2E-AUDIT 2026-07-04 — P2] Le bump depuis le KDS (POST /api/admin/kds-order/change-status, le chemin
 * que les cuisiniers utilisent RÉELLEMENT) doit horodater le temps réel de préparation — au même titre
 * que le bump POS. Régression : mon instrumentation initiale (Wave D) ne couvrait QUE la route POS.
 */
class KdsBumpTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        return $admin;
    }

    private function boardOrder(Branch $branch): Order
    {
        // Board-released (PAID + ACCEPT) pour passer KitchenReleaseRule::orderIsReleasedForBoard.
        return OrderFactory::new()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'source_surface' => 'pos',
            'order_datetime' => now(),
            'accepted_at' => null,
            'preparing_at' => null,
            'prepared_at' => null,
        ]);
    }

    private function kdsBump(User $admin, int $orderId, int $status, int $expectedFrom)
    {
        // Le bump KDS exige `expected_status` (concurrence optimiste : le client envoie le statut qu'il
        // croit courant → 409 si un autre poste a bougé la commande entre-temps).
        return $this->actingAs($admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'kdsbump-' . $orderId . '-' . $status . '-' . Str::random(6))
            ->postJson('/api/admin/kds-order/change-status/' . $orderId, [
                'status' => $status,
                'expected_status' => $expectedFrom,
            ]);
    }

    /** @test */
    public function le_bump_kds_horodate_le_temps_reel_de_preparation(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create();
        $order = $this->boardOrder($branch);

        // ACCEPT → PREPARING via le KDS : preparing_at posé.
        $this->kdsBump($admin, $order->id, OrderStatus::PREPARING, OrderStatus::ACCEPT)->assertStatus(202);
        $order->refresh();
        $this->assertNotNull($order->preparing_at, 'le bump KDS PREPARING doit poser preparing_at');

        // PREPARING → PREPARED via le KDS : prepared_at posé.
        $this->kdsBump($admin, $order->id, OrderStatus::PREPARED, OrderStatus::PREPARING)->assertStatus(202);
        $order->refresh();
        $this->assertNotNull($order->prepared_at, 'le bump KDS PREPARED doit poser prepared_at');
        $this->assertTrue(
            $order->prepared_at->greaterThanOrEqualTo($order->preparing_at),
            'prepared_at >= preparing_at (temps de cuisson mesurable)'
        );
    }
}
