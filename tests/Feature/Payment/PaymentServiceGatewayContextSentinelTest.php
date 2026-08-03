<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\PaymentAbstract;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * [P0-POS-02 GOAL round-2 2026-05-18] Authorization sentinel for
 * `PaymentService::payment()`.
 *
 * Pre-fix, the method had ZERO authz check: any caller (queue job, future
 * controller, console command, malicious dependency) could supply a
 * forged $transactionNo and silently mark an order as PAID — bypassing
 * the actual gateway settlement and corrupting the NF525 chain.
 *
 * Post-fix, the method asserts the calling stack contains at least one
 * frame inside a `PaymentAbstract` subclass (the gateway success
 * callbacks). Direct calls from outside the gateway hierarchy throw
 * HTTP 403.
 *
 * This sentinel verifies both branches:
 *   - direct external call → 403 (deny)
 *   - call via a PaymentAbstract subclass → allowed (mark PAID)
 */
class PaymentServiceGatewayContextSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_direct_call_outside_gateway_context_throws_403(): void
    {
        $order = Order::factory()->create([
            'payment_status' => PaymentStatus::UNPAID,
            'total'          => 10.00,
        ]);

        $service = app(PaymentService::class);

        // Direct invocation from test code — no PaymentAbstract frame above.
        try {
            $service->payment($order, 'credit', 'forged-tx-id');
            $this->fail('Expected HttpException(403) but no exception was thrown.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), 'Gateway context guard must throw 403');
            $this->assertMatchesRegularExpression('/gateway callback/i', $e->getMessage());
        }

        // Belt and braces: order must NOT be PAID even if the exception
        // were somehow swallowed.
        $this->assertNotSame(
            PaymentStatus::PAID,
            (int) $order->fresh()->payment_status,
            'Order must NOT be marked PAID when payment() is called outside gateway context'
        );
    }

    public function test_call_via_payment_abstract_subclass_is_allowed(): void
    {
        $order = Order::factory()->create([
            'payment_status' => PaymentStatus::UNPAID,
            'total'          => 10.00,
        ]);

        $service = app(PaymentService::class);

        // The fake gateway extends PaymentAbstract — so the backtrace will
        // contain a PaymentAbstract subclass frame and the guard allows
        // the call through.
        $fakeGateway = new FakeGatewayForSentinel($service);
        $fakeGateway->invokePayment($order);

        $this->assertSame(
            PaymentStatus::PAID,
            (int) $order->fresh()->payment_status,
            'Order MUST be PAID when payment() is invoked from a PaymentAbstract subclass'
        );
    }

    public function test_runtime_opt_in_flag_allows_one_direct_call_then_clears(): void
    {
        // Escape hatch for tests / console commands that legitimately need
        // to invoke payment() directly. The flag is consumed on each
        // successful pass so it cannot silently leak across calls.
        $order = Order::factory()->create([
            'payment_status' => PaymentStatus::UNPAID,
            'total'          => 10.00,
        ]);

        $service = app(PaymentService::class);

        // First call: flag set → allowed.
        app()->instance('payment.service.allow_direct_call', true);
        $service->payment($order, 'credit', 'console-tx-1');
        $this->assertSame(PaymentStatus::PAID, (int) $order->fresh()->payment_status);

        // Second call without re-setting the flag: must be denied even
        // though the first one set it.
        $orderTwo = Order::factory()->create([
            'payment_status' => PaymentStatus::UNPAID,
            'total'          => 5.00,
        ]);

        try {
            $service->payment($orderTwo, 'credit', 'console-tx-2');
            $this->fail('Expected HttpException(403) after flag was consumed.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), 'Flag must consume after one use');
        }
    }
}

/**
 * Test-only stub mirroring the real gateway pattern (PaymentAbstract).
 * Lives in this file so it is class-loaded only when this test runs and
 * does not pollute the App\ namespace.
 */
class FakeGatewayForSentinel extends PaymentAbstract
{
    public function __construct(PaymentService $service)
    {
        // Skip parent::__construct property-typing to avoid needing a
        // PaymentGateway DB row — we only need the calling-class identity
        // for the backtrace check.
        $this->paymentService = $service;
    }

    public function invokePayment(Order $order): void
    {
        // Real gateway success() callbacks call $this->paymentService->payment(...)
        // — same call shape, so the guard sees this class in the backtrace.
        $this->paymentService->payment($order, 'credit', 'gateway-tx-' . $order->id);
    }

    public function status() { return true; }
    public function payment($order, $request) { return null; }
    public function success($order, $request) { return null; }
    public function fail($order, $request) { return null; }
    public function cancel($order, $request) { return null; }
}
