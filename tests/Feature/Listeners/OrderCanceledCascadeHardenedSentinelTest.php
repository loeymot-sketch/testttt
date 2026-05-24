<?php

namespace Tests\Feature\Listeners;

use App\Events\OrderCanceled;
use App\Events\RefundCreated;
use App\Listeners\ReleaseStockOnOrderCanceled;
use App\Listeners\ReleaseStockOnRefundCreated;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * @FK-ID GOAL-I2-HEAL-01 — OrderCanceled / RefundCreated cascade hardening.
 *
 * @source Phase I.4 RED I4-CONCERN-01 + AMBER I4-CONCERN-02
 * @severity P1 (cascade halt → divergent stock vs availability ledgers)
 *
 * Pre-heal :
 *   - ReleaseStockOnOrderCanceled.php:29 explicitly `throw $e;` per stale iter13
 *     comment. Laravel's sync dispatcher
 *     (vendor/laravel/framework/.../Events/Dispatcher.php:233-269) halts on
 *     listener throw → next listener ReleaseAvailabilityOnOrderCanceled NEVER
 *     ran → divergent stock vs availability ledgers.
 *   - ReleaseStockOnRefundCreated had ZERO try/catch → identical bug class on
 *     the RefundCreated cascade where Stock release sits BEFORE Availability
 *     release per EventServiceProvider:179-186.
 *
 * Post-heal : each release listener wraps its work in try/catch + Log::error +
 * return (mirror DecrementStockOnOrderCreated WG-2 policy). Cascade survives,
 * stock release stays best-effort + observable.
 *
 * StockDecrementFailedEvent is INTENTIONALLY NOT dispatched from the release
 * listeners — its PHPDoc nails it to the OrderCreated cascade. V1.0.2 backlog
 * may introduce a StockReleaseFailedEvent typed hook.
 *
 * Note on mocking strategy : AvailabilityService is `final` (cannot Mockery-mock
 * at runtime), mirroring OrderCreatedFailureIsolationSentinelTest Case 2 we
 * rely on structural source-grep sentinels to lock the contract. StockService
 * is non-final so runtime mock + cascade-survival assertion is possible.
 */
class OrderCanceledCascadeHardenedSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Case 1 (runtime) — Mock StockService::releaseForOrder to throw inside
     * the registered ReleaseStockOnOrderCanceled listener; verify the sibling
     * ReleaseAvailabilityOnOrderCanceled still ran by observing its
     * side-effect through AvailabilityService.
     *
     * Order-independence : we dispatch the real OrderCanceled event so the
     * full EventServiceProvider:167-170 cascade fires in registration order.
     */
    public function test_order_canceled_cascade_survives_stock_release_throw(): void
    {
        $order = $this->createOrder();

        $throwingStock = Mockery::mock(StockService::class);
        $throwingStock->shouldReceive('releaseForOrder')
            ->atLeast()->once()
            ->andThrow(new RuntimeException('Simulated stock release crash'));
        $this->app->instance(StockService::class, $throwingStock);

        // Log::error from the wrapped catch MUST fire (cascade-survival signal).
        Log::shouldReceive('error')
            ->atLeast()->once()
            ->with(
                Mockery::pattern('/ReleaseStockOnOrderCanceled isolated/'),
                Mockery::any()
            );
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('sharedContext')->andReturn([]);

        try {
            OrderCanceled::dispatch($order);
        } catch (\Throwable $e) {
            $this->fail(
                'OrderCanceled cascade re-threw after heal — sibling availability listener '
                . 'would be skipped : ' . $e::class . ' — ' . $e->getMessage()
            );
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Case 2 (runtime, direct) — ReleaseStockOnOrderCanceled MUST NEVER
     * re-throw on Throwable post-heal. Order-independent (advisor pattern).
     */
    public function test_release_stock_on_order_canceled_never_rethrows(): void
    {
        $order = $this->createOrder();

        $throwingStock = Mockery::mock(StockService::class);
        $throwingStock->shouldReceive('releaseForOrder')
            ->once()
            ->andThrow(new RuntimeException('Simulated crash'));
        $this->app->instance(StockService::class, $throwingStock);

        Log::shouldReceive('error')->atLeast()->once();

        $listener = $this->app->make(ReleaseStockOnOrderCanceled::class);

        try {
            $listener->handle(new OrderCanceled($order));
        } catch (\Throwable $e) {
            $this->fail(
                'ReleaseStockOnOrderCanceled re-threw after heal-I2 : '
                . $e::class . ' — ' . $e->getMessage()
            );
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Case 3 (runtime, direct) — ReleaseStockOnRefundCreated MUST absorb
     * Throwable (pre-heal it had ZERO try/catch).
     */
    public function test_release_stock_on_refund_created_never_rethrows(): void
    {
        $order = $this->createOrder();

        $throwingStock = Mockery::mock(StockService::class);
        $throwingStock->shouldReceive('releaseForOrder')
            ->once()
            ->andThrow(new RuntimeException('Simulated crash'));
        $this->app->instance(StockService::class, $throwingStock);

        Log::shouldReceive('error')->atLeast()->once();

        $listener = $this->app->make(ReleaseStockOnRefundCreated::class);

        try {
            $listener->handle(new RefundCreated($order, []));
        } catch (\Throwable $e) {
            $this->fail(
                'ReleaseStockOnRefundCreated re-threw after heal-I2 : '
                . $e::class . ' — ' . $e->getMessage()
            );
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Structural sentinel 1 — locks ReleaseStockOnOrderCanceled.php source so
     * a future agent cannot silently reintroduce the iter13 `throw $e;` regression.
     */
    public function test_release_stock_on_order_canceled_source_has_no_rethrow(): void
    {
        $source = file_get_contents(base_path('app/Listeners/ReleaseStockOnOrderCanceled.php'));

        $this->assertStringNotContainsString(
            'throw $e;',
            $source,
            'GOAL-I2-HEAL-01 : ReleaseStockOnOrderCanceled must NOT re-throw. '
            . 'OrderCanceled uses DispatchableAfterCommit + Laravel sync dispatcher '
            . 'halts on throw → sibling availability listener never runs → divergent '
            . 'stock vs availability ledgers. Iter13 comment justifying the throw was stale.'
        );

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$/',
            $source,
            'GOAL-I2-HEAL-01 : ReleaseStockOnOrderCanceled must keep try/catch on Throwable.'
        );

        $this->assertStringContainsString(
            'Log::error',
            $source,
            'GOAL-I2-HEAL-01 : ReleaseStockOnOrderCanceled must Log::error the absorbed failure.'
        );
    }

    /**
     * Structural sentinel 2 — ReleaseStockOnRefundCreated.php MUST be wrapped
     * (pre-heal it had ZERO try/catch ; AMBER I4-CONCERN-02).
     */
    public function test_release_stock_on_refund_created_source_is_wrapped(): void
    {
        $source = file_get_contents(base_path('app/Listeners/ReleaseStockOnRefundCreated.php'));

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$/',
            $source,
            'GOAL-I2-HEAL-01 : ReleaseStockOnRefundCreated must catch Throwable. '
            . 'Pre-heal it had ZERO try/catch → cascade halt on RefundCreated where '
            . 'Stock release sits BEFORE Availability release.'
        );

        $this->assertStringNotContainsString(
            'throw $e;',
            $source,
            'GOAL-I2-HEAL-01 : ReleaseStockOnRefundCreated must NOT re-throw.'
        );

        $this->assertStringContainsString(
            'Log::error',
            $source,
            'GOAL-I2-HEAL-01 : ReleaseStockOnRefundCreated must Log::error the absorbed failure.'
        );
    }

    /**
     * Structural sentinel 3 — ReleaseAvailabilityOnOrderCanceled.php defense-in-depth
     * wrap (last in cascade today, but symmetry + future-reorder protection).
     */
    public function test_release_availability_on_order_canceled_source_is_wrapped(): void
    {
        $source = file_get_contents(base_path('app/Listeners/ReleaseAvailabilityOnOrderCanceled.php'));

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$/',
            $source,
            'GOAL-I2-HEAL-01 : ReleaseAvailabilityOnOrderCanceled must catch Throwable '
            . '(symmetry + future-reorder defense).'
        );

        $this->assertStringNotContainsString(
            'throw $e;',
            $source,
            'GOAL-I2-HEAL-01 : ReleaseAvailabilityOnOrderCanceled must NOT re-throw.'
        );
    }

    /**
     * Structural sentinel 4 — ReleaseAvailabilityOnRefundCreated.php defense-in-depth.
     */
    public function test_release_availability_on_refund_created_source_is_wrapped(): void
    {
        $source = file_get_contents(base_path('app/Listeners/ReleaseAvailabilityOnRefundCreated.php'));

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$/',
            $source,
            'GOAL-I2-HEAL-01 : ReleaseAvailabilityOnRefundCreated must catch Throwable '
            . '(symmetry + future-reorder defense).'
        );

        $this->assertStringNotContainsString(
            'throw $e;',
            $source,
            'GOAL-I2-HEAL-01 : ReleaseAvailabilityOnRefundCreated must NOT re-throw.'
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createOrder(array $overrides = []): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        return Order::factory()->create(array_merge([
            'user_id'      => $user->id,
            'branch_id'    => $branch->id,
            'queue_number' => 'Q-I2H-' . random_int(1000, 9999),
            'status'       => \App\Enums\OrderStatus::PENDING,
            'order_type'   => 1,
            'total'        => 12.50,
        ], $overrides));
    }
}
