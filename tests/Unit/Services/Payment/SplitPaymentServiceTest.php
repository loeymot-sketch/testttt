<?php

namespace Tests\Unit\Services\Payment;

use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentTerminal;
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

    /**
     * [F-SPLIT-PHANTOM-CARD-001 2026-05-17] Active TPE for CARD tranches.
     * Without this row the new mandatory terminal_id rule would reject every
     * CARD tranche — these unit tests focus on amount/audit, not TPE policy.
     */
    private function createActiveTerminal(int $branchId): PaymentTerminal
    {
        return PaymentTerminal::create([
            'branch_id'    => $branchId,
            'name'         => 'TPE Unit',
            'gateway_type' => PaymentTerminal::GATEWAY_MANUAL,
            'fee_percent'  => 0,
            'fee_fixed'    => 0,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);
    }

    public function test_validate_happy_path_two_tranches_cash_card(): void
    {
        $service = app(SplitPaymentService::class);
        // [F-SPLIT-PHANTOM-CARD-001] CARD tranche requires an active terminal.
        $branch = Branch::factory()->create(['id' => 1]);
        $terminal = $this->createActiveTerminal($branch->id);

        // Doit ne PAS throw.
        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 10.00],
            ['mode' => PosPaymentMethod::CARD, 'amount' => 15.00, 'terminal_id' => $terminal->id],
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

    /**
     * [F-SPLIT-OVERPAY-CASH-ONLY 2026-07-11] Carte seule : AUCUN rendu possible.
     * L'overpay (10,80 vs 10,00) DOIT être rejeté (tolérance effective = 0)
     * sinon la tranche carte est surfacturée de 0,80 €.
     */
    public function test_validate_card_only_overpay_rejected(): void
    {
        $service = app(SplitPaymentService::class);
        $branch = Branch::factory()->create(['id' => 1]);
        $terminal = $this->createActiveTerminal($branch->id);

        $this->expectException(ValidationException::class);
        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CARD, 'amount' => 10.80, 'terminal_id' => $terminal->id],
        ], orderTotal: 10.00, branchId: 1);
    }

    /**
     * [F-SPLIT-OVERPAY-CASH-ONLY 2026-07-11] Cash seul : le tiroir rend la
     * monnaie → overpay ≤ 1 € accepté (11,00 tendu pour 10,00 dû).
     */
    public function test_validate_cash_only_overpay_within_tolerance_accepted(): void
    {
        $service = app(SplitPaymentService::class);

        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CASH, 'amount' => 11.00, 'tendered' => 15.00],
        ], orderTotal: 10.00, branchId: 1);

        $this->assertTrue(true);
    }

    /**
     * [F-SPLIT-OVERPAY-CASH-ONLY 2026-07-11] Mixte carte+cash : présence d'une
     * tranche cash → tolérance active, overpay ≤ 1 € accepté.
     */
    public function test_validate_mixed_card_cash_overpay_within_tolerance_accepted(): void
    {
        $service = app(SplitPaymentService::class);
        $branch = Branch::factory()->create(['id' => 1]);
        $terminal = $this->createActiveTerminal($branch->id);

        // 6,00 carte + 4,80 cash = 10,80 pour un total de 10,00 (+0,80 ≤ 1 €).
        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CARD, 'amount' => 6.00, 'terminal_id' => $terminal->id],
            ['mode' => PosPaymentMethod::CASH, 'amount' => 4.80, 'tendered' => 5.00],
        ], orderTotal: 10.00, branchId: 1);

        $this->assertTrue(true);
    }

    /**
     * [F-SPLIT-OVERPAY-CASH-ONLY 2026-07-11] Le sous-paiement reste rejeté quel
     * que soit le mode (garde-fou inchangé) — carte seule sous le total.
     */
    public function test_validate_card_only_underpay_still_rejected(): void
    {
        $service = app(SplitPaymentService::class);
        $branch = Branch::factory()->create(['id' => 1]);
        $terminal = $this->createActiveTerminal($branch->id);

        $this->expectException(ValidationException::class);
        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CARD, 'amount' => 9.00, 'terminal_id' => $terminal->id],
        ], orderTotal: 10.00, branchId: 1);
    }

    /**
     * [F-SPLIT-OVERPAY-CASH-ONLY 2026-07-13] Une tranche cash TRIVIALE (0,01 €)
     * ne doit PAS réactiver la tolérance 1 € pour masquer un sur-paiement CARTE.
     * cash 0,01 € + carte 10,80 € vs total 10,00 € → REJETÉ (portion non-cash
     * 10,80 € > total 10,00 €).
     */
    public function test_validate_trivial_cash_does_not_shield_card_overpay(): void
    {
        $service = app(SplitPaymentService::class);
        $branch = Branch::factory()->create(['id' => 1]);
        $terminal = $this->createActiveTerminal($branch->id);

        $this->expectException(ValidationException::class);
        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CASH, 'amount' => 0.01, 'tendered' => 0.01],
            ['mode' => PosPaymentMethod::CARD, 'amount' => 10.80, 'terminal_id' => $terminal->id],
        ], orderTotal: 10.00, branchId: 1);
    }

    /**
     * [F-SPLIT-OVERPAY-CASH-ONLY 2026-07-13] Répartition exacte cash+carte sans
     * sur-paiement : cash 5 € + carte 5 € vs total 10 € → accepté.
     */
    public function test_validate_exact_split_cash_card_accepted(): void
    {
        $service = app(SplitPaymentService::class);
        $branch = Branch::factory()->create(['id' => 1]);
        $terminal = $this->createActiveTerminal($branch->id);

        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CASH, 'amount' => 5.00, 'tendered' => 5.00],
            ['mode' => PosPaymentMethod::CARD, 'amount' => 5.00, 'terminal_id' => $terminal->id],
        ], orderTotal: 10.00, branchId: 1);

        $this->assertTrue(true);
    }

    /**
     * [F-SPLIT-OVERPAY-CASH-ONLY 2026-07-13] La carte couvre EXACTEMENT le total
     * (10 € <= 10 €) et le cash (0,50 €) est l'excédent que le tiroir rend :
     * cash 0,50 € + carte 10 € vs total 10 € → accepté (overpay 0,50 € ≤ cash 0,50 €).
     */
    public function test_validate_card_covers_total_cash_makes_change_accepted(): void
    {
        $service = app(SplitPaymentService::class);
        $branch = Branch::factory()->create(['id' => 1]);
        $terminal = $this->createActiveTerminal($branch->id);

        $service->validateBreakdown([
            ['mode' => PosPaymentMethod::CASH, 'amount' => 0.50, 'tendered' => 1.00],
            ['mode' => PosPaymentMethod::CARD, 'amount' => 10.00, 'terminal_id' => $terminal->id],
        ], orderTotal: 10.00, branchId: 1);

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
        // [F-SPLIT-PHANTOM-CARD-001] CARD tranche requires an active terminal.
        $terminal = $this->createActiveTerminal((int) $order->branch_id);

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
            ['mode' => PosPaymentMethod::CARD, 'amount' => 15.00, 'reference' => '1234', 'terminal_id' => $terminal->id],
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

    /**
     * [CAISSE-LOGIC-HEAL 2026-07-11 F2] Le rendu (change_amount) est RECALCULÉ serveur
     * (tendered − amount), jamais pris du client. Un change forgé (99 €) doit être ignoré
     * et remplacé par le vrai rendu (12 − 10 = 2 €) ; une tranche non-cash → rendu 0.
     */
    public function test_persist_recomputes_change_ignoring_forged_client_value(): void
    {
        $this->seedSpatieRoles();
        $order = $this->createOrder(25.00, branchId: 1);
        $terminal = $this->createActiveTerminal((int) $order->branch_id);

        $cashier = User::factory()->create([
            'branch_id' => $order->branch_id,
            'phone' => fake()->unique()->numerify('06########'),
        ]);
        $cashier->assignRole('POS Operator');
        $this->actingAs($cashier);
        app(CashDrawerService::class)->openSession((int) $order->branch_id, $cashier->id, 100.00);

        $service = app(SplitPaymentService::class);

        $service->persistTranches($order, [
            // Client forge un rendu de 99 € ; réel = 12 − 10 = 2 €.
            ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 12.00, 'change' => 99.00],
            // Carte : pas de tendered → rendu 0 (un change forgé serait aussi ignoré).
            ['mode' => PosPaymentMethod::CARD, 'amount' => 15.00, 'reference' => '1234', 'terminal_id' => $terminal->id, 'change' => 50.00],
        ]);

        $rows = OrderPayment::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertEquals(2.00, (float) $rows[0]->change_amount, 'rendu cash recalculé, pas le 99 client');
        $this->assertEquals(0.00, (float) $rows[1]->change_amount, 'tranche carte = rendu 0');
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
        // [F-SPLIT-PHANTOM-CARD-001] Provide a valid terminal so the CARD
        // tranche failure is genuinely the missing-tendered CASH tranche
        // (atomicity assertion), not the new CARD terminal_id guard.
        $terminal = $this->createActiveTerminal((int) $order->branch_id);
        $service = app(SplitPaymentService::class);

        try {
            // 2e tranche cash sans tendered → throws → 1ère NE doit PAS rester
            $service->persistTranches($order, [
                ['mode' => PosPaymentMethod::CARD, 'amount' => 10.00, 'terminal_id' => $terminal->id],
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
