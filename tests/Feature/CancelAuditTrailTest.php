<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\ActionLog;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        config([
            'app.api_key' => 'test-api-key',
            'broadcasting.default' => 'log',
            'fiscal.audit_secret' => str_repeat('c', 48),
            'payment.pilot_restrict.enabled' => true,
            'payment.pilot_restrict.allowed_methods' => ['credit'],
        ]);
    }

    public function test_cancel_writes_action_log_fiscal_audit_and_single_cashback(): void
    {
        [$branch, $cashier, $order] = $this->createCancelablePaidOrder('D05-CANCEL-AUDIT-1');

        $this->actingAs($cashier)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos-order/change-status/' . $order->id, [
                'status' => OrderStatus::CANCELED,
                'reason' => 'customer requested cancellation',
            ])->assertSuccessful();

        $order->refresh();

        $this->assertSame((int) OrderStatus::CANCELED, (int) $order->status);
        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('type', 'cash_back')->count());

        $actionLog = ActionLog::where('action', 'Changement de statut')
            ->where('resource', 'Commande #' . $order->order_serial_no)
            ->latest('id')
            ->first();

        $this->assertNotNull($actionLog, 'Expected ActionLog row for POS cancel.');
        $this->assertSame((int) $branch->id, (int) $actionLog->branch_id);

        $audit = AuditLog::where('action', 'order.cancelled')
            ->where('resource_id', $order->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'Expected fiscal audit row for POS cancel.');
        $this->assertSame((int) $branch->id, (int) $audit->branch_id);
        $this->assertSame((int) OrderStatus::ACCEPT, (int) $audit->payload['from_status']);
        $this->assertSame((int) OrderStatus::CANCELED, (int) $audit->payload['to_status']);
        $this->assertSame('customer requested cancellation', $audit->payload['reason']);
        $this->assertSame(25.0, (float) $audit->payload['total']);

        $cashBackAudit = AuditLog::where('action', 'payment.cash_back_issued')
            ->where('resource_id', $order->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($cashBackAudit, 'Expected fiscal audit row for cancel cash back.');
        $this->assertSame((int) $branch->id, (int) $cashBackAudit->branch_id);

        $this->assertNull(app(AuditLogService::class)->verifyChain($branch->id));

        $this->actingAs($cashier)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos-order/change-status/' . $order->id, [
                'status' => OrderStatus::CANCELED,
                'reason' => 'duplicate cancellation request',
            ])->assertSuccessful();

        $this->assertSame(1, Transaction::where('order_id', $order->id)->where('type', 'cash_back')->count());
        $this->assertSame(1, AuditLog::where('action', 'order.cancelled')->where('resource_id', $order->id)->count());
        $this->assertSame(
            1,
            ActionLog::where('action', 'Changement de statut')
                ->where('resource', 'Commande #' . $order->order_serial_no)
                ->count()
        );
    }

    public function test_cancel_without_reason_is_rejected_without_audit_or_cashback(): void
    {
        [, $cashier, $order] = $this->createCancelablePaidOrder('D05-CANCEL-AUDIT-2');

        $this->actingAs($cashier)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos-order/change-status/' . $order->id, [
                'status' => OrderStatus::CANCELED,
            ])->assertStatus(422);

        $order->refresh();

        $this->assertSame((int) OrderStatus::ACCEPT, (int) $order->status);
        $this->assertSame(0, Transaction::where('order_id', $order->id)->where('type', 'cash_back')->count());
        $this->assertSame(0, AuditLog::where('action', 'order.cancelled')->where('resource_id', $order->id)->count());
        $this->assertSame(0, ActionLog::where('resource', 'Commande #' . $order->order_serial_no)->count());
    }

    private function createCancelablePaidOrder(string $transactionNo): array
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create([
            'branch_id' => $branch->id,
            'balance' => 0,
        ]);
        $cashier->assignRole('POS Operator');

        $order = Order::factory()->create([
            'user_id' => $cashier->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
            'subtotal' => 25.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'total' => 25.00,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'transaction_no' => $transactionNo,
            'amount' => 25.00,
            'payment_method' => 'credit',
            'type' => 'payment',
            'sign' => '+',
        ]);

        return [$branch, $cashier, $order];
    }
}
