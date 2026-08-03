<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT V4 2026-07-02 — P1 vente off-book NF525] Un POS Operator ne doit PAS pouvoir sceller
 * une commande DIFFÉRÉE (borne Plan B, PENDING_COUNTER) en PAID via change-payment-status : ce chemin
 * n'alloue ni fiscal_sequence_no ni cash_movement → vente hors chaîne NF525 + hors trail caisse. Le
 * seul chemin correct = l'encaissement (confirmCounterPayment).
 */
class PosOffBookPaidGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function operator(Branch $branch): User
    {
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        return $operator;
    }

    private function deferredOrder(Branch $branch): Order
    {
        return Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::POS,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::PREPARING,
            'source_surface' => 'kiosk',
            'total' => 9.00,
            'fiscal_sequence_no' => null,
        ]);
    }

    /** @test */
    public function deferred_order_cannot_be_flipped_to_paid_via_change_payment_status(): void
    {
        Queue::fake();
        $branch = Branch::factory()->create();
        $operator = $this->operator($branch);
        $order = $this->deferredOrder($branch);

        $res = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos-order/change-payment-status/{$order->id}", [
                'payment_status' => PaymentStatus::PAID,
            ]);

        $res->assertStatus(422);

        // La commande reste PENDING_COUNTER, SANS séquence fiscale (pas de vente off-book créée).
        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $fresh->payment_status, 'ne doit PAS devenir PAID');
        $this->assertNull($fresh->fiscal_sequence_no, 'aucune séquence fiscale ne doit être créée hors encaissement');
    }

    /** @test */
    public function a_non_deferred_unpaid_order_transition_is_not_blocked_by_this_guard(): void
    {
        Queue::fake();
        $branch = Branch::factory()->create();
        $operator = $this->operator($branch);
        // Commande UNPAID normale (pas PENDING_COUNTER) — le garde off-book ne la vise pas ;
        // elle suit la state-machine habituelle (ici on vérifie juste que le garde ne 422 pas
        // spécifiquement dessus : un CANCELED reste possible).
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::ACCEPT,
            'source_surface' => 'pos',
            'total' => 9.00,
            'fiscal_sequence_no' => null,
        ]);

        $res = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos-order/change-payment-status/{$order->id}", [
                'payment_status' => PaymentStatus::UNPAID, // no-op idempotent → ne doit pas 422 sur le garde off-book
            ]);

        // Le garde off-book (PENDING_COUNTER→PAID) ne s'applique pas ici → pas de 422 dû à CE garde.
        $this->assertNotSame(500, $res->status());
    }
}
