<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Http\Middleware\IdempotencyKeyMiddleware;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * [GOAL ULTRA-DEEP 2026-05-23 Phase B.2 — SYNC CHAIN AGENT C5]
 * Encaisser-borne → KDS preservation contract.
 *
 * When the cashier encaisses a kiosk-cash order via PosCounterCollectModal
 * (Wave X X1), the order's existing state on KDS must NOT be disrupted.
 * The encaissement is a payment confirmation, NOT a fiscal/composition
 * mutation.
 *
 * Five verifications:
 *   V5.1 — KDS state preserved across encaissement (status + items intact)
 *   V5.2 — composition_snapshot frozen invariant (bit-identical pre/post)
 *   V5.3 — audit_logs append-only (count++ by 1, chain still valid)
 *   V5.4 — fiscal_sequence_no preserved (no re-allocation, no gap)
 *   V5.5 — Idempotency-Key replay safety (no double-collect)
 *
 * NF525 §8 invariants enforced.
 */
class C5_EncaisserKdsPreserveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Idempotency middleware ON for V5.5 replay assertion.
        Config::set('idempotency.enabled', true);
        Config::set('idempotency.ttl_seconds', 3600);
        Config::set('idempotency.race_wait_ms', 50);
        Config::set('idempotency.required_routes', []);
        Cache::flush();
    }

    /**
     * V5.1 + V5.2 + V5.3 + V5.4 — combined invariant snapshot.
     *
     * Seed an order ALREADY in PREPARING on KDS (advisor note: NOT ACCEPT,
     * to avoid the Wave S-1 auto-prepare promotion masking a regression),
     * with fiscal_sequence_no=42 pre-allocated (advisor note: tests the
     * "no re-allocation" guard at PaymentService.php:241-243), and an
     * OrderItem carrying a rich composition_snapshot + allergens_snapshot.
     *
     * Confirm via the HTTP route to exercise the full middleware stack.
     */
    public function test_encaissement_preserves_kds_state_composition_audit_chain_and_fiscal_sequence(): void
    {
        Queue::fake();

        [$branch, $operator] = $this->branchOperator();
        $order = $this->preparingCounterOrderWithSnapshot($branch, [
            'fiscal_sequence_no' => 42, // pre-allocated (kiosk paid path)
            'total' => 18.50,
        ]);
        $orderItem = OrderItem::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($orderItem, 'OrderItem seed must succeed.');

        // ── PRE snapshot ──────────────────────────────────────────────
        $preOrder = Order::query()->whereKey($order->id)->first();
        $preStatus = (int) $preOrder->status;
        $preFiscalSequence = (int) $preOrder->fiscal_sequence_no;
        $preCompositionRaw = DB::table('order_items')
            ->where('id', $orderItem->id)
            ->value('composition_snapshot');
        $preAllergensRaw = DB::table('order_items')
            ->where('id', $orderItem->id)
            ->value('allergens_snapshot');
        $preCompositionHash = hash('sha256', (string) $preCompositionRaw);
        $preAllergensHash = hash('sha256', (string) $preAllergensRaw);
        $preAuditLogCount = AuditLog::query()
            ->where('branch_id', $branch->id)
            ->count();
        $preAuditLastHash = AuditLog::query()
            ->where('branch_id', $branch->id)
            ->orderByDesc('id')
            ->value('current_hash');

        $this->assertSame(
            OrderStatus::PREPARING,
            $preStatus,
            'Pre-condition: order must be PREPARING on KDS before encaissement.'
        );

        // ── ACT: confirm counter-collect via HTTP route ───────────────
        $response = $this->actingAs($operator, 'sanctum')
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 20.00,
                'note' => 'C5 NF525 invariant test',
            ]);
        $response->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID);

        // ── POST snapshot ─────────────────────────────────────────────
        $postOrder = Order::query()->whereKey($order->id)->first();
        $postStatus = (int) $postOrder->status;
        $postFiscalSequence = (int) $postOrder->fiscal_sequence_no;
        $postCompositionRaw = DB::table('order_items')
            ->where('id', $orderItem->id)
            ->value('composition_snapshot');
        $postAllergensRaw = DB::table('order_items')
            ->where('id', $orderItem->id)
            ->value('allergens_snapshot');
        $postCompositionHash = hash('sha256', (string) $postCompositionRaw);
        $postAllergensHash = hash('sha256', (string) $postAllergensRaw);
        $postAuditLogCount = AuditLog::query()
            ->where('branch_id', $branch->id)
            ->count();
        $postAuditLastHash = AuditLog::query()
            ->where('branch_id', $branch->id)
            ->orderByDesc('id')
            ->value('current_hash');

        // ── V5.1 — KDS state preserved ────────────────────────────────
        $this->assertSame(
            $preStatus,
            $postStatus,
            'V5.1 — Order status MUST remain PREPARING after encaissement (KDS card unchanged).'
        );
        $this->assertSame(
            PaymentStatus::PAID,
            (int) $postOrder->payment_status,
            'V5.1 — Only payment_status mutates (PENDING_COUNTER → PAID).'
        );
        $this->assertSame(
            PosPaymentMethod::CASH,
            (int) $postOrder->pos_payment_method,
            'V5.1 — pos_payment_method updates (cash mode selected).'
        );
        $this->assertEqualsWithDelta(
            20.00,
            (float) $postOrder->pos_received_amount,
            0.01,
            'V5.1 — pos_received_amount updates (cash received).'
        );

        // ── V5.2 — composition_snapshot bit-identical ─────────────────
        $this->assertSame(
            $preCompositionRaw,
            $postCompositionRaw,
            'V5.2 — composition_snapshot MUST be bit-identical post-encaissement (NF525 §8).'
        );
        $this->assertSame(
            $preCompositionHash,
            $postCompositionHash,
            'V5.2 — composition_snapshot SHA-256 MUST match pre/post.'
        );
        $this->assertSame(
            $preAllergensRaw,
            $postAllergensRaw,
            'V5.2 — allergens_snapshot MUST be bit-identical post-encaissement.'
        );
        $this->assertSame(
            $preAllergensHash,
            $postAllergensHash,
            'V5.2 — allergens_snapshot SHA-256 MUST match pre/post.'
        );

        // ── V5.3 — audit_logs append-only + chain valid ───────────────
        $this->assertSame(
            $preAuditLogCount + 1,
            $postAuditLogCount,
            'V5.3 — audit_logs count MUST advance by exactly 1 (counter_payment_confirmed event).'
        );
        $this->assertDatabaseHas('audit_logs', [
            'branch_id' => $branch->id,
            'action' => 'order.counter_payment_confirmed',
            'resource' => 'order',
            'resource_id' => $order->id,
        ]);
        // Chain MUST remain HMAC-valid (verifyChain returns null iff OK).
        $brokenRowId = app(AuditLogService::class)->verifyChain((int) $branch->id);
        $this->assertNull(
            $brokenRowId,
            'V5.3 — audit_logs chain HMAC MUST verify (CHAIN OK).'
        );

        // ── V5.4 — fiscal_sequence_no preserved (no re-allocation) ────
        $this->assertSame(
            42,
            $preFiscalSequence,
            'V5.4 sanity — pre-existing fiscal_sequence_no must be 42 (kiosk paid path).'
        );
        $this->assertSame(
            $preFiscalSequence,
            $postFiscalSequence,
            'V5.4 — fiscal_sequence_no MUST be preserved (no re-allocation, no gap).'
        );
        $this->assertSame(
            42,
            $postFiscalSequence,
            'V5.4 — fiscal_sequence_no MUST stay 42 (the encaissement is a payment confirmation, NOT a fiscal mutation).'
        );

        // Persist the empirical snapshot for the JSON report.
        $reportPath = storage_path('app/c5-encaisser-kds-preserve.report.json');
        @file_put_contents($reportPath, json_encode([
            'pre' => [
                'status' => $preStatus,
                'fiscal_sequence_no' => $preFiscalSequence,
                'composition_snapshot_sha256' => $preCompositionHash,
                'allergens_snapshot_sha256' => $preAllergensHash,
                'audit_logs_count' => $preAuditLogCount,
                'audit_logs_last_hash' => $preAuditLastHash,
            ],
            'post' => [
                'status' => $postStatus,
                'fiscal_sequence_no' => $postFiscalSequence,
                'composition_snapshot_sha256' => $postCompositionHash,
                'allergens_snapshot_sha256' => $postAllergensHash,
                'audit_logs_count' => $postAuditLogCount,
                'audit_logs_last_hash' => $postAuditLastHash,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * V5.5 — Idempotency-Key replay safety.
     *
     * Two HTTP POSTs with the SAME Idempotency-Key header within the same
     * minute. Middleware (IdempotencyKeyMiddleware, Wave K Z7) MUST replay
     * the cached 200 response, AND no second audit_log row may be appended.
     */
    public function test_idempotent_replay_does_not_double_collect(): void
    {
        Queue::fake();

        [$branch, $operator] = $this->branchOperator();
        $order = $this->preparingCounterOrderWithSnapshot($branch, [
            'fiscal_sequence_no' => 17,
            'total' => 12.00,
        ]);

        $key = 'pos-counter-collect-' . $order->id . '-CASH-test';

        $first = $this->actingAs($operator, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 12.00,
            ]);
        $first->assertOk();

        $auditCountAfterFirst = AuditLog::query()
            ->where('branch_id', $branch->id)
            ->where('action', 'order.counter_payment_confirmed')
            ->count();

        // Replay with same key.
        $second = $this->actingAs($operator, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 12.00,
            ]);

        $second->assertStatus(200);
        $this->assertSame(
            'true',
            $second->headers->get(IdempotencyKeyMiddleware::REPLAY_HEADER),
            'V5.5 — Replay header MUST be set on second response (cached replay).'
        );

        $auditCountAfterSecond = AuditLog::query()
            ->where('branch_id', $branch->id)
            ->where('action', 'order.counter_payment_confirmed')
            ->count();

        $this->assertSame(
            $auditCountAfterFirst,
            $auditCountAfterSecond,
            'V5.5 — audit_logs MUST NOT advance on idempotent replay (no double-collect).'
        );
    }

    /**
     * V5.5 sister guard — service-level short-circuit on payment_status=PAID.
     *
     * Two POSTs with DIFFERENT idempotency keys but same order ID.
     * PaymentService::confirmCounterPayment lines 227-230 detect
     * `payment_status === PAID` and return without mutating. This catches
     * a failure mode the middleware cache would hide.
     */
    public function test_second_confirm_with_different_key_is_a_noop(): void
    {
        Queue::fake();

        [$branch, $operator] = $this->branchOperator();
        $order = $this->preparingCounterOrderWithSnapshot($branch, [
            'fiscal_sequence_no' => 99,
            'total' => 9.50,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, 'first-attempt-' . $order->id)
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 9.50,
            ])
            ->assertOk();

        $countAfterFirst = AuditLog::query()
            ->where('action', 'order.counter_payment_confirmed')
            ->where('resource_id', $order->id)
            ->count();
        $this->assertSame(1, $countAfterFirst);

        $second = $this->actingAs($operator, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, 'second-attempt-' . $order->id)
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 9.50,
            ]);
        $second->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID);

        $countAfterSecond = AuditLog::query()
            ->where('action', 'order.counter_payment_confirmed')
            ->where('resource_id', $order->id)
            ->count();
        $this->assertSame(
            1,
            $countAfterSecond,
            'V5.5 sister — service-level short-circuit MUST prevent a second audit_logs row even when middleware cache misses.'
        );

        // fiscal_sequence_no still preserved (no re-allocation either).
        $this->assertSame(99, (int) $order->fresh()->fiscal_sequence_no);
    }

    /**
     * @return array{0: Branch, 1: User}
     */
    private function branchOperator(): array
    {
        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');

        return [$branch, $operator];
    }

    /**
     * Seed a kiosk-counter-deferred order ALREADY in PREPARING (visible on
     * KDS) with a non-trivial composition_snapshot + allergens_snapshot
     * on the underlying OrderItem.
     */
    private function preparingCounterOrderWithSnapshot(Branch $branch, array $overrides = []): Order
    {
        $order = Order::factory()->create($overrides + [
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::PREPARING, // Already on KDS
            'source_surface' => 'kiosk',
            'subtotal' => 12.00,
            'total' => 12.00,
            'discount' => 0,
            'delivery_charge' => 0,
        ]);

        // Item must exist for the FK constraint on order_items.item_id.
        $item = Item::factory()->create();

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 12.00,
            'total_price' => 12.00,
            'tax_amount' => 0,
            'discount' => 0,
            'composition_snapshot' => [
                'item_name' => 'Le Suprême',
                'steps' => [
                    ['step_no' => 1, 'label' => 'Viande', 'choice' => 'Poulet'],
                    ['step_no' => 2, 'label' => 'Crudités', 'choices' => ['Salade', 'Tomate']],
                    ['step_no' => 3, 'label' => 'Sauce', 'choice' => 'Algérienne'],
                ],
                'price' => 12.00,
            ],
            'allergens_snapshot' => ['1', '7'], // gluten, lait (FR codes)
        ]);

        return $order;
    }
}
