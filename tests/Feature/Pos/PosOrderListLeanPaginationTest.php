<?php

namespace Tests\Feature\Pos;

use App\Http\Requests\PaginateRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [POSPERF-07-tracker-unbounded 2026-07-22] The POS tracker poll fetched EVERY
 * order of the day: it sent `per_page: 100` but no `paginate` flag, so
 * OrderService::list ran ->get('*') (unbounded) with 8 heavy eager relations.
 *
 * Fix (backend half): honour `paginate` so per_page bounds the result, and an
 * opt-in `lean` flag swaps the heavy OrderResource eager-load set — which the
 * tracker's SimpleOrderResource never reads — for its real needs
 * (transaction / user / orderItems.orderItem). Callers WITHOUT `lean` (Historique
 * list, OrderExport) are byte-for-byte unchanged.
 *
 * These specs lock:
 *  1. paginate=1 + per_page → bounded page (the unbounded fetch is gone);
 *  2. default (no paginate) → still returns ALL rows (get) — unchanged;
 *  3. lean=1 → trims branch/transaction.order/user.roles/user.media/media/category
 *     while STILL loading what SimpleOrderResource needs (transaction/orderItems/user);
 *  4. default (no lean) → full relation set intact.
 */
class PosOrderListLeanPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        return $admin;
    }

    private function req(array $params): PaginateRequest
    {
        // Manual construction — we exercise list() directly (no HTTP validation),
        // reading params via get()/all() exactly as the controller-bound request does.
        return PaginateRequest::create('/api/admin/pos-order', 'GET', $params);
    }

    /** @test */
    public function paginate_flag_bounds_the_result_to_per_page(): void
    {
        $this->actingAs($this->admin());
        $branch = Branch::factory()->create();
        Order::factory()->count(3)->create(['branch_id' => $branch->id, 'order_datetime' => now()]);

        $result = app(OrderService::class)->list($this->req([
            'paginate' => 1,
            'per_page' => 2,
        ]));

        // LengthAwarePaginator: current page capped at per_page, total sees all 3.
        $this->assertSame(2, $result->count(), 'paginate=1 + per_page=2 must cap the page at 2 rows.');
        $this->assertSame(3, $result->total(), 'The paginator total still reflects every matching order.');
    }

    /** @test */
    public function without_paginate_the_legacy_get_returns_all_rows_unchanged(): void
    {
        $this->actingAs($this->admin());
        $branch = Branch::factory()->create();
        Order::factory()->count(3)->create(['branch_id' => $branch->id, 'order_datetime' => now()]);

        $result = app(OrderService::class)->list($this->req([]));

        // Non-paginate path is untouched — Historique/export still get the full set.
        $this->assertCount(3, $result, 'Default (no paginate) must keep the ->get() behaviour = every row.');
    }

    /** @test */
    public function lean_flag_trims_heavy_relations_but_keeps_the_tracker_needs(): void
    {
        $this->actingAs($this->admin());
        $branch = Branch::factory()->create();
        Order::factory()->create(['branch_id' => $branch->id, 'order_datetime' => now()]);

        $order = app(OrderService::class)->list($this->req([
            'paginate' => 1,
            'per_page' => 10,
            'lean'     => 1,
        ]))->first();

        // Heavy OrderResource-only relations are NOT eager-loaded on the lean path.
        // `branch` is the cleanest witness: required column, always resolves, and
        // is absent from the lean set (present in the full set — asserted below).
        $this->assertFalse($order->relationLoaded('branch'), 'lean must NOT eager-load branch.');

        // But everything SimpleOrderResource reads IS still loaded (no N+1, no nulls).
        $this->assertTrue($order->relationLoaded('transaction'), 'lean must still load transaction (payment_method).');
        $this->assertTrue($order->relationLoaded('orderItems'), 'lean must still load orderItems (item names on the card).');
        $this->assertTrue($order->relationLoaded('user'), 'lean must still load user (customer name/phone).');
    }

    /** @test */
    public function default_no_lean_keeps_the_full_relation_set(): void
    {
        $this->actingAs($this->admin());
        $branch = Branch::factory()->create();
        Order::factory()->create(['branch_id' => $branch->id, 'order_datetime' => now()]);

        $order = app(OrderService::class)->list($this->req([
            'paginate' => 1,
            'per_page' => 10,
        ]))->first();

        // Historique/export path — full eager-load unchanged.
        $this->assertTrue($order->relationLoaded('branch'), 'Non-lean must still eager-load branch (OrderResource/export).');
        $this->assertTrue($order->relationLoaded('user'), 'Non-lean must eager-load user.');
        $this->assertTrue($order->relationLoaded('orderItems'), 'Non-lean must eager-load orderItems.');
    }
}
