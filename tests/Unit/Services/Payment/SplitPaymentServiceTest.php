<?php

namespace Tests\Unit\Services\Payment;

use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\Payments\SplitPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * F-SPLIT-PAYMENT-001 — Tests unitaires {@see SplitPaymentService}.
 *
 * Couvre la validation (sum vs total, modes, cash tendered, max tranches)
 * et la persistence (rows order_payments, audit-log NF525, atomicité).
 */
class SplitPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['split_payment.enabled' => true, 'split_payment.max_tranches' => 12]);
        // Le secret fiscal est requis par AuditLogService::write().
        config(['fiscal.audit_secret' => 'test-fiscal-secret-' . str_repeat('a', 40)]);
    }

    private function createOrder(float $total, int $branchId = 1): Order
    {
        $branch = Branch::factory()->create(['id' => $branchId]);
        return Order::factory()->create([
            'branch_id' => $branch->id,
            'total' => $total,
            'subtotal' => $total,
        ]);
    }

    public function test_validate_happy_path_two_tranches_cash_card(): void
    {
        $service = app(SplitPaymentService::class);

        // Doit ne PAS throw.
        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 10.00],
            ['mode' => PosPaymentMethod::CARD, 'amount' => 15.00],
        ], orderTotal: 25.00, branchId: 1);

        $this->assertTrue(true); // pas d'exception = succès
    }

    public function test_validate_sum_below_total_throws(): void
    {
        $service = app(SplitPaymentService::class);

        $this->expectException(ValidationException::class);
        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 10.00],
        ], orderTotal: 25.00, branchId: 1);
    }

    public function test_validate_sum_above_total_plus_tolerance_throws(): void
    {
        $service = app(SplitPaymentService::class);

        // 25 + 1.50 € (tolerance overpay = 1.00 €) → throws
        $this->expectException(ValidationException::class);
        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CASH, 'amount' => 26.50, 'tendered' => 30.00],
        ], orderTotal: 25.00, branchId: 1);
    }

    public function test_validate_sum_within_tolerance_accepted(): void
    {
        $service = app(SplitPaymentService::class);

        // 25 + 0.50 € (tolérance = 1.00 €) → OK
        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CASH, 'amount' => 25.50, 'tendered' => 30.00],
        ], orderTotal: 25.00, branchId: 1);

        $this->assertTrue(true);
    }

    public function test_validate_cash_tranche_without_tendered_throws(): void
    {
        $service = app(SplitPaymentService::class);

        $this->expectException(ValidationException::class);
        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CASH, 'amount' => 25.00],
        ], orderTotal: 25.00, branchId: 1);
    }

    public function test_validate_cash_tendered_below_amount_throws(): void
    {
        $service = app(SplitPaymentService::class);

        $this->expectException(ValidationException::class);
        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CASH, 'amount' => 25.00, 'tendered' => 20.00],
        ], orderTotal: 25.00, branchId: 1);
    }

    public function test_validate_unknown_mode_throws(): void
    {
        $service = app(SplitPaymentService::class);

        $this->expectException(ValidationException::class);
        // 99 = mode inconnu (whitelist = 1..5)
        $service->validateBreakdown([
            ['mode' => 99, 'amount' => 25.00],
        ], orderTotal: 25.00, branchId: 1);
    }

    public function test_persist_two_tranches_creates_two_rows_with_branch_id(): void
    {
        $this->seedSpatieRoles();
        $order = $this->createOrder(25.00, branchId: 1);

        // [Sprint 1B 2026-05-16] CASH tranche path needs an authenticated
        // cashier with an OPEN cash drawer session on the order's branch.
        $cashier = User::factory()->create([
            'branch_id' => $order->branch_id,
            'phone' => fake()->unique()->numerify('06########'),
        ]);
        $cashier->assignRole('POS Operator');
        $this->actingAs($cashier);
        app(CashDrawerService::class)->openSession((int) $order->branch_id, $cashier->id, 100.00);

        $service = app(SplitPaymentService::class);

        $persisted = $service->persistTranches($order, [
            ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 12.00, 'change' => 2.00],
            ['mode' => PosPaymentMethod::CARD, 'amount' => 15.00, 'reference' => '1234'],
        ]);

        $this->assertCount(2, $persisted);
        $rows = OrderPayment::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame((int) $order->branch_id, (int) $rows[0]->branch_id);
        $this->assertSame((int) $order->branch_id, (int) $rows[1]->branch_id);
        $this->assertSame(PosPaymentMethod::CASH, (int) $rows[0]->mode);
        $this->assertSame(PosPaymentMethod::CARD, (int) $rows[1]->mode);
        $this->assertEquals(10.00, (float) $rows[0]->amount);
        $this->assertEquals(15.00, (float) $rows[1]->amount);
        $this->assertEquals(2.00, (float) $rows[0]->change_amount);
        $this->assertSame('1234', $rows[1]->reference);

        // Audit-log : une ligne par tranche
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order.payment_tranche_persisted',
            'resource' => 'order_payment',
            'resource_id' => (int) $rows[0]->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order.payment_tranche_persisted',
            'resource' => 'order_payment',
            'resource_id' => (int) $rows[1]->id,
        ]);
    }

    public function test_persist_returns_empty_when_flag_disabled(): void
    {
        config(['split_payment.enabled' => false]);
        $order = $this->createOrder(25.00, branchId: 1);
        $service = app(SplitPaymentService::class);

        $persisted = $service->persistTranches($order, [
            ['mode' => PosPaymentMethod::CASH, 'amount' => 25.00, 'tendered' => 25.00],
        ]);

        $this->assertCount(0, $persisted);
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_persist_atomicity_rollback_when_validation_fails(): void
    {
        $order = $this->createOrder(25.00, branchId: 1);
        $service = app(SplitPaymentService::class);

        try {
            // 2e tranche cash sans tendered → throws → 1ère NE doit PAS rester
            $service->persistTranches($order, [
                ['mode' => PosPaymentMethod::CARD, 'amount' => 10.00],
                ['mode' => PosPaymentMethod::CASH, 'amount' => 15.00], // pas de tendered → fail
            ]);
            $this->fail('ValidationException attendue.');
        } catch (ValidationException $e) {
            // OK
        }

        // La validation se fait AVANT toute insertion : aucune ligne ne doit exister.
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_persist_max_tranches_limit_enforced(): void
    {
        $order = $this->createOrder(120.00, branchId: 1);
        $service = app(SplitPaymentService::class);

        // 13 tranches → trop (max 12)
        $tranches = [];
        for ($i = 0; $i < 13; $i++) {
            $tranches[] = ['mode' => PosPaymentMethod::CARD, 'amount' => 10.00];
        }

        $this->expectException(ValidationException::class);
        $service->persistTranches($order, $tranches);
    }
}
