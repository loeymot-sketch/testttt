<?php

namespace Tests\Feature\Order;

use App\Enums\Ask;
use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * @FK-ID  AUDIT-F-004
 * @plan   .claude/worktrees/blissful-mclean-c915c2/plans/PLAN_AUDIT_F004_CANCEL_REASON_ENFORCE_2026-05-07.md
 *
 * Enforces ORDER_FLOW.md §49 invariant: every transition to CANCELED, REJECTED or RETURNED
 * MUST carry a non-empty `reason`. Pre-fix RED state:
 *
 *  - Kiosk path POST /api/frontend/order/change-status/{id} with status=CANCELED and no
 *    reason succeeds (200) and persists `reason=NULL` in `order_status_transitions` —
 *    audit trail is blind.
 *
 * Post-fix expectations:
 *
 *  - Admin POS path: free-text reason still accepted (back-compat with PosOrderBL2).
 *  - Kiosk path: missing reason → 422; whitelist-only enforcement when actor is a kiosk
 *    machine token; valid whitelisted code persists in audit row.
 */
class CancelReasonEnforceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $kioskUser;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        config(['app.api_key' => 'test-api-key']);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'F004 Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '4 rue F004',
            'status' => 1,
        ]);

        $this->kioskUser = User::forceCreate([
            'name' => 'Kiosk F004',
            'email' => 'kiosk-f004@test.local',
            'username' => 'kiosk_f004',
            'phone' => '0600000041',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
        ]);

        KioskMachine::forceCreate([
            'user_id' => $this->kioskUser->id,
            'branch_id' => $this->branch->id,
            'machine_id' => 'machine-f004-01',
            'username' => 'kiosk-f004',
            'password' => bcrypt('kiosk123'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->admin->assignRole('Admin');
    }

    /* -------------------- Kiosk path (FrontendOrderService) -------------------- */

    /** @test */
    public function kiosk_cancel_without_reason_is_rejected_422(): void
    {
        $order = $this->makeKioskPendingOrder();

        $kioskToken = $this->kioskUser->createToken('kiosk-f004', ['kiosk:order'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $kioskToken)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/frontend/order/change-status/{$order->id}", [
                'status' => OrderStatus::CANCELED,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);

        // Order must NOT have transitioned.
        $this->assertSame(
            OrderStatus::PENDING,
            (int) FrontendOrder::withoutGlobalScopes()->findOrFail($order->id)->status,
            'Kiosk cancel without reason must NOT mutate order status.'
        );
    }

    /** @test */
    public function kiosk_cancel_with_unknown_reason_code_is_rejected_422(): void
    {
        $order = $this->makeKioskPendingOrder();

        $kioskToken = $this->kioskUser->createToken('kiosk-f004', ['kiosk:order'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $kioskToken)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/frontend/order/change-status/{$order->id}", [
                'status' => OrderStatus::CANCELED,
                'reason' => 'totally_made_up_code',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    /** @test */
    public function kiosk_cancel_with_whitelisted_reason_succeeds_and_persists_reason(): void
    {
        $order = $this->makeKioskPendingOrder();

        $kioskToken = $this->kioskUser->createToken('kiosk-f004', ['kiosk:order'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $kioskToken)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/frontend/order/change-status/{$order->id}", [
                'status' => OrderStatus::CANCELED,
                'reason' => OrderCancelReason::TPE_CANCEL_USER,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('order_status_transitions', [
            'order_id'   => $order->id,
            'order_type' => FrontendOrder::class,
            'to_status'  => OrderStatus::CANCELED,
            'reason'     => OrderCancelReason::TPE_CANCEL_USER,
        ]);
    }

    /* -------------------- Admin POS path (OrderService) -------------------- */

    /** @test */
    public function admin_pos_cancel_with_free_text_reason_remains_accepted(): void
    {
        // Back-compat assertion: admin path historically accepts free-text reasons
        // (see PosOrderBL2AuditCallSitesTest::test_change_status_to_cancel_writes_order_cancelled_audit
        // which sends 'Client désistement'). The whitelist must NOT regress this surface.
        $order = $this->makeAdminPosPendingOrder();

        $response = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::CANCELED,
                'reason' => 'Client désistement', // free-text, NOT in enum
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('order_status_transitions', [
            'order_id'   => $order->id,
            'order_type' => Order::class,
            'to_status'  => OrderStatus::CANCELED,
            'reason'     => 'Client désistement',
        ]);
    }

    /** @test */
    public function admin_pos_cancel_without_reason_is_rejected_422(): void
    {
        // Already enforced today by OrderService::changeStatus inline validate; we lock it
        // in via a Feature test so the base-class refactor cannot weaken the guard.
        $order = $this->makeAdminPosPendingOrder();

        $response = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::CANCELED,
            ]);

        $response->assertStatus(422);
    }

    /* -------------------- Non-terminal transitions are unaffected -------------------- */

    /** @test */
    public function admin_pos_non_terminal_transition_without_reason_is_accepted(): void
    {
        $order = $this->makeAdminPosPendingOrder();

        $response = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::ACCEPT,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', [
            'id'     => $order->id,
            'status' => OrderStatus::ACCEPT,
        ]);
    }

    /* -------------------- Helpers -------------------- */

    private function makeKioskPendingOrder(): FrontendOrder
    {
        return FrontendOrder::forceCreate([
            'order_serial_no' => 'F004K-' . substr(uniqid(), -8),
            'user_id'         => $this->kioskUser->id,
            'branch_id'       => $this->branch->id,
            'status'          => OrderStatus::PENDING,
            'payment_status'  => PaymentStatus::UNPAID,
            'payment_method'  => PaymentGateway::CARD,
            'order_type'      => OrderType::KIOSK,
            'source'          => Source::APP,
            'subtotal'        => 10.00,
            'total'           => 10.00,
            'discount'        => 0,
            'delivery_charge' => 0,
            'order_datetime'  => now(),
            'preparation_time' => 30,
            'queue_number'    => 'F' . random_int(1000, 9999),
        ]);
    }

    private function makeAdminPosPendingOrder(): Order
    {
        return Order::factory()->create([
            'user_id'        => $this->admin->id,
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::POS,
            'status'         => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'queue_number'   => 'P' . random_int(1000, 9999),
        ]);
    }
}
