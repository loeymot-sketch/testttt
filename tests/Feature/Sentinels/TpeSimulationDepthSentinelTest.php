<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * @FK-ID FK-TPE-SIMULATION-DEPTH
 * @source Phase L Agent L1.1 (GOAL ULTRA-FINAL PRE-CLOUD 2026-05-24)
 *
 * TPE Simulation Depth Sentinel — locks the architectural invariant that
 * `POS_SIMULATION_HARDWARE=true` (the dev convenience while the physical
 * cash drawer is not wired) does NOT silently disable the F-002 TPE
 * "amount echo" verification on the kiosk-paymentConfirm callback.
 *
 * Architectural context (recorded in Phase L L1.1 findings):
 *   - `POS_SIMULATION_HARDWARE` is scoped to PosController +
 *     SplitPaymentService + PaymentService::recordCashOrderMovement —
 *     it lifts the open-CashDrawerSession precondition for CASH-bearing
 *     POS sales when no physical drawer exists. NF525 fiscal invariants
 *     (sequence, audit chain, composition_snapshot) remain enforced.
 *   - The physical TPE flow (Electron-app callback into
 *     `OrderController::paymentConfirm` and `PaymentReconcileController`)
 *     is a DIFFERENT code path. It is gated by the AMOUNT_ECHO_MISMATCH
 *     check (F-002) which MUST fire on every call regardless of the
 *     simulation flag. Without this sentinel, a future refactor could
 *     widen the simulation_hardware bypass into the F-002 gate and
 *     silently allow a TPE-driver bug (or compromised driver) to mark
 *     any kiosk order PAID with an arbitrary `amount_cents`.
 *
 * Anti-régression invariant tested here:
 *   With POS_SIMULATION_HARDWARE=true (the documented Le Cayenne V1
 *   pre-hardware production state), the kiosk paymentConfirm endpoint
 *   MUST still return 422 + error_code AMOUNT_ECHO_MISMATCH when the
 *   driver echoes a wrong `amount_cents`, AND the order MUST remain
 *   UNPAID.
 */
class TpeSimulationDepthSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_amount_echo_mismatch_still_fires_under_pos_simulation_hardware_under(): void
    {
        Config::set('pos.simulation_hardware', true);

        $order = $this->createKioskCardOrder(50.00);

        $response = $this->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-SIM-UNDER-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents'   => 100, // 1€ vs 50€ order
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error_code' => 'AMOUNT_ECHO_MISMATCH']);

        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) $order->fresh()->payment_status,
            'Order must remain UNPAID even though simulation_hardware is true — F-002 must not be bypassed.'
        );
    }

    public function test_amount_echo_mismatch_still_fires_under_pos_simulation_hardware_over(): void
    {
        Config::set('pos.simulation_hardware', true);

        $order = $this->createKioskCardOrder(50.00);

        $response = $this->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-SIM-OVER-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents'   => 5500, // 55€ vs 50€ order
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error_code' => 'AMOUNT_ECHO_MISMATCH']);

        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) $order->fresh()->payment_status,
            'Order must remain UNPAID — over-pay echo must not be accepted under simulation.'
        );
    }

    public function test_exact_amount_still_accepted_under_pos_simulation_hardware(): void
    {
        Config::set('pos.simulation_hardware', true);

        $order = $this->createKioskCardOrder(50.00);

        $response = $this->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-SIM-OK-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents'   => 5000,
        ]);

        // Happy path must NOT spuriously fire F-002 when simulation is on.
        // Downstream may return 200 or 422 (FCM/event sync sidecar) but the
        // error code MUST NOT be AMOUNT_ECHO_MISMATCH.
        // [ULTRA-AUDIT 2026-07-02] Assertion INCONDITIONNELLE (avant : dans `if 422` →
        // 0 assertion sur le happy-path 200 → test « risky »). L'invariant tient pour les
        // deux statuts : un 200 n'a pas d'`error_code` (null ≠ AMOUNT_ECHO_MISMATCH).
        $this->assertContains(
            $response->status(),
            [200, 201, 422],
            'Exact-amount echo must not 500 under simulation (got ' . $response->status() . ').'
        );
        $this->assertNotSame(
            'AMOUNT_ECHO_MISMATCH',
            $response->json('error_code'),
            'Exact-amount echo must never raise F-002 under simulation.'
        );
    }

    public function test_reconcile_path_amount_echo_still_fires_under_pos_simulation_hardware(): void
    {
        // PaymentReconcileController::reconcileEntry is the F-008 batch
        // reconciliation surface — must also keep F-002 active under
        // simulation. Without this, a kiosk's localStorage queue replay
        // could quietly mark any order PAID with a wrong amount.
        Config::set('pos.simulation_hardware', true);

        $order = $this->createKioskCardOrder(50.00);

        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [
                [
                    'order_id'       => $order->id,
                    'transaction_id' => 'TX-RECON-SIM-' . uniqid(),
                    'amount_cents'   => 100, // wrong
                    'card_type'      => 'VISA',
                    'payment_method' => PaymentGateway::CARD,
                ],
            ],
        ]);

        // PaymentReconcileController returns 200 with per-entry status — the
        // mismatch entry must be marked 'amount_mismatch'.
        $response->assertStatus(200);
        $body = $response->json();
        $entry = $body['data'][0] ?? null;
        $this->assertNotNull($entry, 'reconcile must return a per-entry result row.');
        $this->assertSame('amount_mismatch', $entry['status'] ?? null,
            'reconcile must reject wrong amount echo even under simulation_hardware.');

        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) $order->fresh()->payment_status,
            'Order must remain UNPAID after a reconcile amount mismatch.',
        );
    }

    private function createKioskCardOrder(float $total): FrontendOrder
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::query()->create([
            'name'       => 'TEST-KIOSK-' . uniqid(),
            'machine_id' => 'KM-' . uniqid(),
            'username'   => 'kiosk-test-' . uniqid(),
            'password'   => 'pwd',
            'user_id'    => $user->id,
            'branch_id'  => $branch->id,
        ]);

        Sanctum::actingAs($user, ['kiosk:order']);

        return FrontendOrder::query()->create([
            'user_id'          => $user->id,
            'branch_id'        => $branch->id,
            'total'            => $total,
            'subtotal'         => $total,
            'discount'         => 0,
            'payment_status'   => PaymentStatus::UNPAID,
            'payment_method'   => PaymentGateway::CARD,
            'status'           => OrderStatus::PENDING,
            'order_datetime'   => now(),
            'preparation_time' => 15,
        ]);
    }
}
