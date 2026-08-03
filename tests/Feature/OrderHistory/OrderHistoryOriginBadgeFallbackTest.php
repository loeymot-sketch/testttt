<?php

namespace Tests\Feature\OrderHistory;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [GOAL_MGMT_TESTPLAN Wave B / HIST-05] Data-integrity guard for the unified
 * order-history origin badge fallback.
 *
 * GROUNDING (verified by source Read, anti-false-finding):
 *  - The history endpoint (OrderHistoryController::index → SimpleOrderResource)
 *    does NOT derive a human-facing origin label server-side. It ships the raw
 *    `source_surface` column verbatim:
 *        SimpleOrderResource.php:58  'source_surface' => $this->source_surface
 *    NULL passes through as null; a dirty value passes through unchanged.
 *  - The actual badge derivation (kiosk→Borne, pos→Caisse, …) lives client-side
 *    in HistoriqueListComponent.vue::originBadge() (line 285). Its default case
 *    for NULL/unknown/dirty surfaces (with no order_type/source override) is
 *    label "En ligne" (online) — a frontend concern, not reachable here.
 *
 * WHAT THIS TEST GUARANTEES at the endpoint layer (the backend invariant the
 * badge depends on): an order whose source_surface is NULL or a bogus/dirty
 * string is NEVER server-side coerced into a WRONG CONCRETE origin (it does not
 * silently become 'kiosk'/'pos'/'web'). The value is returned deterministically
 * — null stays null, dirty stays the exact dirty string — and the endpoint
 * returns 200 (no crash). A regression that injected a coercion (e.g. defaulting
 * NULL to 'kiosk') or threw on a dirty value would flip these hard assertions.
 *
 * Mirrors OrderHistoryUnifiedTest setup patterns (admin branch_id=0 bypasses
 * BranchScope; sanctum acting-as; /api/admin/order-history?paginate=1).
 */
class OrderHistoryOriginBadgeFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        // branch_id=0 admin bypasses BranchScope → sees every origin.
        $this->admin = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('pwd'),
        ]);
        $this->admin->assignRole('Admin');
    }

    /**
     * Force the column straight to the DB so model boot()/save hooks
     * (e.g. the DELIVERY auto-derive of source_surface) cannot rewrite the
     * NULL/dirty value we are deliberately testing. order_type is TAKEAWAY so
     * no concrete order_type override masks the fallback path.
     */
    private function makeOrderWithRawSurface(?string $sourceSurface): Order
    {
        $order = Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::TAKEAWAY,
            'source_surface' => 'pos', // placeholder, overwritten below
            'payment_status' => PaymentStatus::PAID,
            'total'          => 10.00,
        ]);

        // Bypass Eloquent mutators/boot — set the literal column value.
        Order::withoutEvents(function () use ($order, $sourceSurface) {
            Order::query()->whereKey($order->id)->update(['source_surface' => $sourceSurface]);
        });

        return $order->fresh();
    }

    private function findRow(array $data, int $id): ?array
    {
        foreach ($data as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
        return null;
    }

    public function test_null_source_surface_is_not_coerced_to_a_concrete_origin(): void
    {
        $order = $this->makeOrderWithRawSurface(null);

        $this->actingAs($this->admin, 'sanctum');
        $res = $this->getJson('/api/admin/order-history?paginate=1&per_page=50');

        // No crash on a NULL surface row.
        $res->assertOk();

        $row = $this->findRow($res->json('data'), $order->id);
        $this->assertNotNull($row, 'NULL-surface order must still appear in the unified history.');

        // Deterministic passthrough: NULL stays NULL, never silently rewritten
        // into a wrong concrete origin like 'kiosk'/'pos'/'web'/'mobile'/'app'.
        $this->assertNull(
            $row['source_surface'],
            'A NULL source_surface must remain NULL in the payload, never coerced to a concrete origin.'
        );
        $this->assertNotContains(
            $row['source_surface'],
            ['kiosk', 'pos', 'web', 'mobile', 'app'],
            'Endpoint must not mis-badge an unknown origin as a concrete surface.'
        );
    }

    public function test_dirty_source_surface_is_returned_verbatim_not_mislabeled(): void
    {
        $dirty = '  KiOsK-junk!! ';
        $order = $this->makeOrderWithRawSurface($dirty);

        $this->actingAs($this->admin, 'sanctum');
        $res = $this->getJson('/api/admin/order-history?paginate=1&per_page=50');

        // No crash on a dirty/unknown surface row.
        $res->assertOk();

        $row = $this->findRow($res->json('data'), $order->id);
        $this->assertNotNull($row, 'Dirty-surface order must still appear in the unified history.');

        // Deterministic: the exact stored string is echoed back. It is NOT
        // silently normalized into the canonical 'kiosk' concrete origin (which
        // the frontend would badge "Borne") despite superficially containing it.
        $this->assertSame(
            $dirty,
            $row['source_surface'],
            'A dirty source_surface must be returned verbatim, never silently coerced to a clean concrete origin.'
        );
        $this->assertNotContains(
            $row['source_surface'],
            ['kiosk', 'pos', 'web', 'mobile', 'app'],
            'Endpoint must not mis-badge a dirty origin as a clean concrete surface.'
        );
    }
}
