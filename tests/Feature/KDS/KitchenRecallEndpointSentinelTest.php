<?php

/**
 * [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
 *
 * Sentinel test for `POST /api/admin/kds-order/recall/{order}`. Locks the
 * full Path B contract:
 *
 *   1. Success path (within 60s) → 200, OrderStatusTransition row appended
 *      with reason='kitchen_recall', orders.status STAYS PREPARED.
 *   2. Window expired (>60s after the bump) → 422, NO row.
 *   3. Anonymous (no actor) → 401/403/302 (any non-2xx denial).
 *   4. orders.status is bit-identical before and after the recall
 *      (NF525-safe append-only invariant — the load-bearing test).
 *   5. Cap N=1 — second recall on the same order within the window → 409.
 *   6. Wrong state (e.g. ACCEPT) → 422 (only PREPARED is recallable).
 *   7. Cross-branch attempt — branch-A chef recalling a branch-B order → 404
 *      (BranchScope filters the route binding) OR 403 if the lock check
 *      catches it first.
 *
 * Patterns mirrored from KdsHistoryTodayEndpointTest (seedSpatieRoles +
 * seedMinimalSettings + actingAs sanctum + x-api-key).
 */

namespace Tests\Feature\Kds;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KitchenRecallEndpointSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function endpoint(Order $order): string
    {
        return '/api/admin/kds-order/recall/' . $order->id;
    }

    private function actingAsChef(int $branchId): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);
        $user->assignRole('Chef');
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function preparedOrder(Branch $branch, ?Carbon $updatedAt = null): Order
    {
        return Order::factory()->create([
            'branch_id'  => $branch->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::PREPARED,
            'updated_at' => $updatedAt ?? Carbon::now(),
        ]);
    }

    /** @test */
    public function recall_succeeds_within_60s_window_and_appends_transition_row(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsChef($branch->id);

        // Order bumped 30s ago — well inside the 60s window.
        $order = $this->preparedOrder($branch, Carbon::now()->subSeconds(30));

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson($this->endpoint($order));

        $response->assertOk();
        $response->assertJsonStructure(['status', 'transition_id', 'recalled_at']);
        $this->assertSame(true, $response->json('status'), 'success envelope must report status=true');

        // Exactly one kitchen_recall row appended.
        $this->assertEquals(
            1,
            OrderStatusTransition::query()
                ->where('order_id', $order->id)
                ->where('reason', 'kitchen_recall')
                ->count(),
            'Exactly one kitchen_recall row must be appended.'
        );

        // Row carries from=to=PREPARED (identity transition) + actor id set.
        $row = OrderStatusTransition::query()
            ->where('order_id', $order->id)
            ->where('reason', 'kitchen_recall')
            ->first();
        $this->assertSame(OrderStatus::PREPARED, (int) $row->from_status, 'from_status must be PREPARED');
        $this->assertSame(OrderStatus::PREPARED, (int) $row->to_status, 'to_status must be PREPARED (identity)');
        $this->assertNotNull($row->actor_id, 'actor_id must be set');
    }

    /** @test */
    public function recall_rejects_after_60s_window_with_no_row_appended(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsChef($branch->id);

        // Order bumped 90s ago — outside the 60s window.
        $order = $this->preparedOrder($branch, Carbon::now()->subSeconds(90));

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson($this->endpoint($order));

        $response->assertStatus(422);

        $this->assertEquals(
            0,
            OrderStatusTransition::query()
                ->where('order_id', $order->id)
                ->where('reason', 'kitchen_recall')
                ->count(),
            'No kitchen_recall row must be appended when window is expired.'
        );
    }

    /** @test */
    public function recall_requires_authenticated_actor(): void
    {
        $branch = Branch::factory()->create();
        $order = $this->preparedOrder($branch);

        // No actingAs — guest. KDS endpoints sit behind auth middleware.
        $response = $this->postJson($this->endpoint($order));

        $this->assertTrue(
            in_array($response->status(), [401, 403, 302, 419], true),
            "Guest must be denied; got HTTP {$response->status()}."
        );

        $this->assertEquals(
            0,
            OrderStatusTransition::query()
                ->where('order_id', $order->id)
                ->where('reason', 'kitchen_recall')
                ->count(),
            'No row must be appended on unauthenticated request.'
        );
    }

    /** @test */
    public function recall_rejects_authenticated_user_without_kds_permission(): void
    {
        // [Heal-5 advisor concern 3] Difference between auth gate vs authz gate:
        // a user can be authenticated AND have a recognised role
        // (e.g. POS Operator), but if they don't carry the
        // `kitchen-display-system` permission, the controller middleware
        // must deny the recall. Without this assertion, a non-chef role
        // (e.g. a future operator persona that lands in POS Operator group
        // but not chef-permission) would silently slip through.
        $branch = Branch::factory()->create();
        $order = $this->preparedOrder($branch, Carbon::now()->subSeconds(10));

        // Create a user with a benign role that does NOT carry the
        // `kitchen-display-system` permission. Customer is a safe choice
        // (zero KDS permissions by definition).
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->assignRole('Customer');
        $this->actingAs($user, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson($this->endpoint($order));

        $this->assertTrue(
            in_array($response->status(), [401, 403], true),
            "Authenticated-but-unauthorized user must be denied (401/403); got HTTP {$response->status()}."
        );

        $this->assertEquals(
            0,
            OrderStatusTransition::query()
                ->where('order_id', $order->id)
                ->where('reason', 'kitchen_recall')
                ->count(),
            'No row must be appended on permission-less actor.'
        );
    }

    /** @test */
    public function recall_does_not_mutate_orders_status(): void
    {
        // [NF525 invariant] The load-bearing test: after a successful recall,
        // SELECT status FROM orders WHERE id = X must return PREPARED, bit-
        // identical to the pre-recall snapshot. This pins the append-only
        // contract that the entire Path B design rests on.
        $branch = Branch::factory()->create();
        $this->actingAsChef($branch->id);

        $order = $this->preparedOrder($branch, Carbon::now()->subSeconds(20));

        $statusBefore = (int) Order::query()->whereKey($order->id)->value('status');
        $this->assertSame(OrderStatus::PREPARED, $statusBefore, 'pre-recall status must be PREPARED');

        $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson($this->endpoint($order))
            ->assertOk();

        $statusAfter = (int) Order::query()->whereKey($order->id)->value('status');
        $this->assertSame(
            OrderStatus::PREPARED,
            $statusAfter,
            'orders.status MUST remain PREPARED after recall (NF525 append-only invariant).'
        );
        $this->assertSame(
            $statusBefore,
            $statusAfter,
            'orders.status must be bit-identical before and after the recall.'
        );
    }

    /** @test */
    public function recall_is_capped_at_one_per_order(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsChef($branch->id);

        $order = $this->preparedOrder($branch, Carbon::now()->subSeconds(10));

        // First recall — must succeed.
        $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson($this->endpoint($order))
            ->assertOk();

        // Second recall on the same order — must 409.
        $second = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson($this->endpoint($order));

        $second->assertStatus(409);

        $this->assertEquals(
            1,
            OrderStatusTransition::query()
                ->where('order_id', $order->id)
                ->where('reason', 'kitchen_recall')
                ->count(),
            'Cap N=1 — only one kitchen_recall row may exist per order in the window.'
        );
    }

    /** @test */
    public function recall_rejects_when_status_is_not_prepared(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsChef($branch->id);

        // ACCEPT order — not yet bumped, no recall semantics apply.
        $order = Order::factory()->create([
            'branch_id'  => $branch->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::ACCEPT,
            'updated_at' => Carbon::now()->subSeconds(20),
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson($this->endpoint($order));

        $response->assertStatus(422);

        $this->assertEquals(
            0,
            OrderStatusTransition::query()
                ->where('order_id', $order->id)
                ->where('reason', 'kitchen_recall')
                ->count(),
            'Wrong-state recall must NOT append a row.'
        );
    }

    /** @test */
    public function recall_is_branch_scoped_for_branch_staff(): void
    {
        // Branch A chef trying to recall a branch-B order. BranchScope filters
        // the route binding before the controller is reached, so the
        // attempted lookup must miss → 404. (If for any reason the binding
        // surfaces the row, the service's lockForUpdate + branch_id recheck
        // would 403 instead — both are valid denials, neither must succeed.)
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $this->actingAsChef($branchA->id);

        $orderB = $this->preparedOrder($branchB, Carbon::now()->subSeconds(10));

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/kds-order/recall/' . $orderB->id);

        $this->assertTrue(
            in_array($response->status(), [403, 404], true),
            "Cross-branch recall must be denied (404 BranchScope OR 403 lock); got HTTP {$response->status()}."
        );

        $this->assertEquals(
            0,
            OrderStatusTransition::query()
                ->where('order_id', $orderB->id)
                ->where('reason', 'kitchen_recall')
                ->count(),
            'Cross-branch recall must NOT append a row on the target branch.'
        );
    }
}
