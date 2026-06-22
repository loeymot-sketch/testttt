<?php

namespace Tests\Feature\Symmetry;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Events\OrderCanceled;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\FrontendOrderService;
use App\Services\LoyaltyService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OrderServicesContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->app->instance(AuditLogService::class, new class {
            public function write(array $payload): void {}
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_method_contract_documents_intentional_payment_asymmetry(): void
    {
        $matrix = file_get_contents(base_path('docs/orchestration/OS_FOS_SYMMETRY_MATRIX_2026-04-25.md'));
        $this->assertStringContainsString('CV1-LOT-D07-FOS-SYMMETRY-CONTRACT', $matrix);
        $this->assertStringContainsString('OrderService::changePaymentStatus', $matrix);
        $this->assertStringContainsString('intentionally absent from `FrontendOrderService`', $matrix);

        $this->assertSame('orders', (new Order())->getTable());
        $this->assertSame('orders', (new FrontendOrder())->getTable());

        $this->assertTrue(method_exists(OrderService::class, 'posOrderStore'));
        $this->assertTrue(method_exists(OrderService::class, 'myOrderStore'));
        $this->assertTrue(method_exists(OrderService::class, 'tableOrderStore'));
        $this->assertTrue(method_exists(OrderService::class, 'changeStatus'));
        $this->assertTrue(method_exists(OrderService::class, 'changePaymentStatus'));
        $this->assertTrue(method_exists(OrderService::class, 'collectKioskCash'));

        $this->assertTrue(method_exists(FrontendOrderService::class, 'myOrderStore'));
        $this->assertTrue(method_exists(FrontendOrderService::class, 'changeStatus'));
        $this->assertTrue(method_exists(FrontendOrderService::class, 'finalizePaidKioskOrder'));
        $this->assertFalse(
            method_exists(FrontendOrderService::class, 'changePaymentStatus'),
            'FOS payment status mutation must stay kiosk paymentConfirm/finalize only.'
        );

        $routes = file_get_contents(base_path('routes/api.php'));
        $frontendOrderRoutes = $this->frontendOrderRouteGroup($routes);

        $this->assertStringContainsString("Route::post('/{frontendOrder}/payment-confirm'", $frontendOrderRoutes);
        $this->assertStringNotContainsString('change-payment-status', $frontendOrderRoutes);
    }

    public function test_branch_and_dispatch_contract_is_source_anchored(): void
    {
        $orderService = file_get_contents(base_path('app/Services/OrderService.php'));
        $paymentService = file_get_contents(base_path('app/Services/PaymentService.php'));
        $frontendService = file_get_contents(base_path('app/Services/FrontendOrderService.php'));
        $frontendController = file_get_contents(base_path('app/Http/Controllers/Frontend/OrderController.php'));

        $this->assertStringContainsString("if (\$key === 'branch_id')", $orderService);
        $this->assertStringContainsString("\$query->where('branch_id', '=', (int) \$value);", $orderService);
        $this->assertStringContainsString("\$query->where('branch_id', '=', (int) \$request);", $frontendService);
        $this->assertStringContainsString("(int) \$locked->branch_id !== (int) \$kioskMachine->branch_id", $frontendController);

        // [abuse-heal 2026-06-19 deliv-admin-twin] Anchor on the use(...) PREFIX
        // (no trailing ")") so the contract stays robust across additive by-ref
        // captures — the admin path now also threads `&$cashEscrowMeta` to record
        // the COD cash-collection post-commit, mirroring the prefix-robust anchors
        // below (e.g. FrontendService `&$promoted`). The ordering assertion still
        // proves the locked tx wraps and precedes the SendOrderMail dispatch.
        $this->assertSourceOrder(
            $orderService,
            'DB::transaction(function () use ($order, $request, $targetStatus, &$oldStatusForBroadcast',
            "SendOrderMail::dispatch(['order_id' => \$order->id, 'status' => \$targetStatus]);"
        );
        $this->assertStringContainsString('return app(PaymentService::class)->confirmCounterPayment(', $orderService);
        $this->assertSourceOrder(
            $paymentService,
            'DB::transaction(function () use ($order, $mode, $received, $note, &$paid): void',
            'if ($paid) {'
        );
        $this->assertSourceOrder($paymentService, 'if ($paid) {', 'OrderPaidAtCounter::dispatch($order, $mode);');
        $this->assertSourceOrder(
            $frontendController,
            'DB::transaction(function () use ($frontendOrder, $request, $kioskMachine',
            '$promoted = $this->frontendOrderService->finalizePaidKioskOrder'
        );
        // [Wave M / Heal Z2 P1 + Z5 P1-C — 2026-05-19] The
        // `use(...)` clause now also imports `&$allocFailed,
        // &$allocFailureError` for the deferred (outside-parent-tx)
        // fiscal_alloc_error_at flag write. Anchor on the prefix to
        // remain robust across future additions; keep ordering
        // assertion intact (parent tx still wraps the alloc; queue
        // jobs still dispatch via `dispatchNewOrderSignals` after).
        // OrderCreated::dispatch was moved INSIDE the closure (so the
        // helper no longer dispatches it) — anchored by separate
        // sentinel `OrderCreatedDispatchPlacementSentinelTest`.
        $this->assertSourceOrder(
            $frontendService,
            'DB::transaction(function () use ($frontendOrder, &$promoted',
            '$this->dispatchNewOrderSignals($frontendOrder);'
        );
    }

    public function test_os_status_and_payment_noops_do_not_emit_side_effects(): void
    {
        Event::fake([OrderStatusChanged::class, OrderCanceled::class]);

        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('POS Operator');

        $order = Order::factory()->create([
            'user_id' => $cashier->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::CANCELED,
            'total' => 25.00,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'transaction_no' => 'FK-M10-NOOP-PAYMENT',
            'amount' => 25.00,
            'payment_method' => 'cash',
            'type' => 'payment',
            'sign' => '+',
        ]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('cashBack')->never();
        $this->app->instance(PaymentService::class, $payment);

        $loyalty = Mockery::mock(LoyaltyService::class);
        $loyalty->shouldReceive('refundPoints')->never();
        $this->app->instance(LoyaltyService::class, $loyalty);

        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('write')->never();
        $this->app->instance(AuditLogService::class, $audit);

        // [prod-finale 2026-06-17] change-status/* and change-payment-status/* are idempotency-guarded; these
        // are two DISTINCT guarded routes, each needs its own X-Idempotency-Key to reach the controller where
        // the no-op side-effect contract is asserted (frozen middleware; live UI sends it).
        $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos-order/change-status/'.$order->id, [
            'status' => OrderStatus::CANCELED,
            'reason' => 'contract noop',
        ])->assertSuccessful();

        $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos-order/change-payment-status/'.$order->id, [
            'payment_status' => PaymentStatus::PAID,
        ])->assertSuccessful();

        $this->assertSame(OrderStatus::CANCELED, (int) $order->fresh()->status);
        $this->assertSame(PaymentStatus::PAID, (int) $order->fresh()->payment_status);
        Event::assertNotDispatched(OrderStatusChanged::class);
        Event::assertNotDispatched(OrderCanceled::class);
    }

    public function test_fos_status_noop_does_not_emit_cancel_side_effects(): void
    {
        Event::fake([OrderStatusChanged::class, OrderCanceled::class]);

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['user_id' => $kioskUser->id, 'branch_id' => $branch->id]);

        $order = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::CANCELED,
            'source_surface' => 'kiosk',
            'total' => 50.00,
            'subtotal' => 50.00,
        ]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('cashBack')->never();
        $this->app->instance(PaymentService::class, $payment);

        $loyalty = Mockery::mock(LoyaltyService::class);
        $loyalty->shouldReceive('refundPoints')->never();
        $this->app->instance(LoyaltyService::class, $loyalty);

        // [prod-finale 2026-06-17] frontend/order/change-status/* is idempotency-guarded; the header is needed
        // to reach the controller where the no-op contract is asserted (frozen middleware; live UI sends it).
        $token = $kioskUser->createToken('kiosk', ['kiosk:order'])->plainTextToken;
        $this->withToken($token)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order/change-status/'.$order->id, [
            'status' => OrderStatus::CANCELED,
            // [AUDIT-F-004] reason whitelisted required on terminal transitions (kiosk path)
            'reason' => 'customer_request',
        ])->assertSuccessful();

        $this->assertSame(OrderStatus::CANCELED, (int) Order::withoutGlobalScopes()->findOrFail($order->id)->status);
        Event::assertNotDispatched(OrderStatusChanged::class);
        Event::assertNotDispatched(OrderCanceled::class);
    }

    public function test_fos_deferred_payment_confirm_golden_response_is_idempotent(): void
    {
        Event::fake();

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['user_id' => $kioskUser->id, 'branch_id' => $branch->id]);

        $order = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
            'transaction_id' => null,
            'card_type' => null,
            'total' => 50.00,
            'subtotal' => 50.00,
        ]);

        $token = $kioskUser->createToken('kiosk', ['kiosk:order'])->plainTextToken;
        $payload = [
            'transaction_id' => 'FK-M10-GOLDEN-TPE',
            'card_type' => 'visa',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents' => 5000, // [AUDIT-F-002] matches order.total=50.00
        ];

        // [prod-finale 2026-06-17] payment-confirm is idempotency-guarded. DISTINCT X-Idempotency-Key per POST:
        // this test asserts the CONTROLLER's transaction_id-level idempotency (golden response + count()===1),
        // so the SECOND POST below MUST reach the controller — reusing one key would make the frozen middleware
        // replay the first 2xx from cache and never exercise the controller dedup under test.
        $this->withToken($token)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order/'.$order->id.'/payment-confirm', $payload)
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.order_id', $order->id);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        // [Wave S-1 — 2026-05-20] Owner P-OWNER Wave S-1: kiosk paid TPE
        // auto-advances to PREPARING after finalize. Idempotent replay
        // observes the same final status (verified by the second POST below
        // that returns OK without changing the row).
        $this->assertSame(OrderStatus::PREPARING, (int) $fresh->status);
        $this->assertSame('FK-M10-GOLDEN-TPE', $fresh->transaction_id);

        // [prod-finale 2026-06-17] DISTINCT key (see note above) — this replay must reach the controller so the
        // transaction_id dedup keeps row count at 1, not be short-circuited by the middleware's replay cache.
        $this->withToken($token)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order/'.$order->id.'/payment-confirm', $payload)
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.order_id', $order->id);

        $this->assertSame(1, Order::withoutGlobalScopes()->where('transaction_id', 'FK-M10-GOLDEN-TPE')->count());
    }

    private function assertSourceOrder(string $source, string $firstNeedle, string $secondNeedle): void
    {
        $firstPosition = strpos($source, $firstNeedle);
        $secondPosition = strpos($source, $secondNeedle);

        $this->assertNotFalse($firstPosition, "Missing source anchor: {$firstNeedle}");
        $this->assertNotFalse($secondPosition, "Missing source anchor: {$secondNeedle}");
        $this->assertLessThan($secondPosition, $firstPosition);
    }

    private function frontendOrderRouteGroup(string $routes): string
    {
        $startNeedle = "Route::prefix('order')->name('order.')->middleware(['auth:sanctum'])->group(function () {";
        $endNeedle = "Route::prefix('offer')->name('offer.')->group(function () {";
        $start = strpos($routes, $startNeedle);
        $end = strpos($routes, $endNeedle, $start ?: 0);

        $this->assertNotFalse($start, 'Missing frontend order route group.');
        $this->assertNotFalse($end, 'Missing frontend order route group terminator.');

        return substr($routes, $start, $end - $start);
    }
}
