<?php

namespace Tests\Feature\Idempotency;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Middleware\IdempotencyKeyMiddleware;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Impl F — Idempotency Precision Sweep (Round 2).
 *
 * Verifies the 4 POST routes that Round 1 RED-team flagged but were
 * precision-refined by the orchestrator. Each route is now wrapped
 * with the 'idempotency' middleware alias.
 *
 * Test strategy: TWO assertion tiers.
 *
 *   1. WIRING — for all 4 routes, assert the 'idempotency' middleware
 *      is bound to the route (via Route::getRoutes()->getByName()
 *      ->gatherMiddleware()). This proves the GAP is closed at the
 *      routing layer — exactly what Agent 10 flagged.
 *
 *   2. BEHAVIORAL — for print-receipt (which returns 200 in test env
 *      because the controller is self-contained), additionally exercise
 *      the full middleware stack: POST twice with the same key, assert
 *      the second response carries `Idempotency-Replayed: true`, AND
 *      assert receipt_print_count did NOT advance from 1 to 2.
 *
 * The wiring tests are the contract assertion (the GAP IS closed).
 * The behavioral test is the end-to-end attestation (the wiring
 * actually produces the at-most-once execution semantic).
 *
 * Covered routes:
 *  - POST /api/admin/pos/counter-collect/{order}/confirm   (L745)
 *  - POST /api/admin/pos/counter-collect/{order}/cancel    (L769)
 *  - POST /api/admin/pos/collect-kiosk-cash/{order}        (L789)
 *  - POST /api/admin/pos/orders/{order}/print-receipt      (L800)
 *
 * Discipline pattern adapted from tests/Feature/Idempotency/IdempotencyMiddlewareTest.php.
 */
class CounterCollectAndPrintIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable middleware in test env (default OFF for safe roll-out)
        // — line 41-43 of IdempotencyKeyMiddleware short-circuits otherwise.
        Config::set('idempotency.enabled', true);
        Config::set('idempotency.ttl_seconds', 3600);
        Config::set('idempotency.race_wait_ms', 50);
        // Leave required_routes empty so missing header doesn't force 422.
        // Behavioral assertion is on header-IS-sent path (replay-on-second).
        Config::set('idempotency.required_routes', []);

        Cache::flush();

        // Parent helper seeds Admin/POS Operator roles under guard:sanctum
        // with the 'pos' permission (required by abort_unless(can('pos'))).
        $this->seedSpatieRoles();
    }

    private function makePosUser(int $branchId): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);
        $user->assignRole('POS Operator');

        return $user;
    }

    private function assertRouteHasIdempotencyMiddleware(string $routeName): void
    {
        $route = RouteFacade::getRoutes()->getByName($routeName);
        $this->assertNotNull($route, "Route '{$routeName}' must exist in api routes.");

        $middleware = $route->gatherMiddleware();
        $this->assertContains(
            'idempotency',
            $middleware,
            "Route '{$routeName}' must include 'idempotency' middleware. Found: "
                . implode(', ', $middleware)
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // WIRING TIER — 4 routes, assert 'idempotency' bound by name.
    // ──────────────────────────────────────────────────────────────────

    public function test_counter_collect_confirm_route_is_wrapped_with_idempotency_middleware(): void
    {
        $this->assertRouteHasIdempotencyMiddleware('admin.pos.counter-collect.confirm');
    }

    public function test_counter_collect_cancel_route_is_wrapped_with_idempotency_middleware(): void
    {
        $this->assertRouteHasIdempotencyMiddleware('admin.pos.counter-collect.cancel');
    }

    public function test_collect_kiosk_cash_route_is_wrapped_with_idempotency_middleware(): void
    {
        $this->assertRouteHasIdempotencyMiddleware('admin.pos.collect-kiosk-cash');
    }

    public function test_print_receipt_route_is_wrapped_with_idempotency_middleware(): void
    {
        $this->assertRouteHasIdempotencyMiddleware('admin.pos.orders.print-receipt');
    }

    // ──────────────────────────────────────────────────────────────────
    // BEHAVIORAL TIER — print-receipt end-to-end replay attestation.
    // ──────────────────────────────────────────────────────────────────

    public function test_print_receipt_is_idempotent_on_replay_no_double_count(): void
    {
        $branch = Branch::factory()->create();
        $user   = $this->makePosUser($branch->id);
        $order  = Order::factory()->create([
            'branch_id'      => $branch->id,
            'user_id'        => $user->id,
            'order_type'     => OrderType::TAKEAWAY,
            'payment_status' => PaymentStatus::PAID,
            'status'         => OrderStatus::DELIVERED,
        ]);

        $key = 'print-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);

        $first = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt");

        $second = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt");

        $first->assertStatus(200);

        // Replay must echo first response exactly — receipt_print_count
        // must NOT advance from 1 → 2 on the replay.
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(
            'true',
            $second->headers->get(IdempotencyKeyMiddleware::REPLAY_HEADER),
            'Replay header must be flagged on the cached response.'
        );

        $order->refresh();
        $this->assertSame(
            1,
            (int) $order->receipt_print_count,
            'NF525 duplicata count MUST NOT advance on idempotent replay.'
        );
    }
}
