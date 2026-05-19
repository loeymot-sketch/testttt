<?php

namespace Tests\Feature\Refund;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Order\RefundWithCounterEntryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * [HEAL-A.3 + A.3-bis + A.4 / Z8 P0-1 — WAVE L]
 *
 * Cluster sentinel for the "exactly one counter-entry per parent order"
 * invariant. Pre-heal a buggy client sending two distinct X-Idempotency-Key
 * values could pass the idempotency middleware twice and create two mirror
 * orders against the same parent — yielding TWO Z negatives against a single
 * sale. The L73-78 status-check guard in RefundWithCounterEntryService never
 * fires on this path because NF525 immutability forbids parent.status mutation.
 *
 * Heals validated by this test:
 *   A.3       — migration 2026_05_19_200000 promotes orders.parent_order_id
 *               from non-unique INDEX to UNIQUE.
 *   A.3-bis   — PosOrderController::refundWithCounterEntry catches
 *               QueryException SQLSTATE 23000 → 409 MIRROR_ALREADY_EXISTS.
 *   A.4       — documentation-only annotation above the L73-78 guard
 *               (no behavioral change; verified by parallel sentinel tests
 *               that still pass — RefundMirrorSplitPaymentTest:189 and
 *               RefundCounterEntryRequiresSealedParentSentinelTest:115).
 *
 * Test cases:
 *   1. Service-level: first execute() succeeds, second execute() raises
 *      QueryException with SQLSTATE 23000.
 *   2. DB invariant: after the failed second attempt, exactly ONE row in
 *      `orders` carries the given parent_order_id.
 *   3. HTTP-level: POST /api/admin/pos-order/{parent}/refund-with-counter-entry
 *      twice (mocking the service to skip idempotency middleware key check)
 *      surfaces a stable 409 with code=MIRROR_ALREADY_EXISTS.
 *   4. Nullability invariant: multiple non-mirror orders (parent_order_id IS
 *      NULL) coexist without violating the UNIQUE (MySQL/SQLite semantics).
 */
class RefundCounterEntryUniqueParentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Stub fiscal services to avoid touching real HMAC chain / sequence
        // allocator. We're testing the orders-table UNIQUE, not fiscal logic.
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct()
            {
            }

            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });

        // Use a counter so each next() returns a fresh sequence number —
        // a second mirror attempt would otherwise reuse seq=9001 and hit a
        // unique-fiscal-seq violation before reaching the parent_order_id one.
        $sequenceCounter = 9000;
        $this->app->instance(FiscalSequenceService::class, new class($sequenceCounter) extends FiscalSequenceService {
            private int $counter;

            public function __construct(int $start)
            {
                $this->counter = $start;
            }

            public function next(int $branchId): int
            {
                return ++$this->counter;
            }
        });
    }

    private function sealZ(Branch $branch, Carbon $opened, Carbon $closed): void
    {
        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);
    }

    private function makeSealedParent(Branch $branch, Carbon $within): Order
    {
        $parent = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal'       => 30.00,
            'total'          => 30.00,
            'total_tax'      => 0,
            'discount'       => 0,
            'created_at'     => $within,
        ]);
        $parent->fiscal_sequence_no = 500;
        $parent->save();
        return $parent->fresh();
    }

    /**
     * Heal A.3 primary assertion: a second counter-entry attempt against the
     * same parent is rejected at the DB layer with SQLSTATE 23000 — even
     * though the L73-78 status guard does NOT fire (parent.status remains
     * unchanged per NF525 immutability).
     */
    public function test_second_mirror_attempt_violates_unique_parent_order_id(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('Admin');
        Auth::setUser($cashier);

        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch, $opened, $closed);

        $parent = $this->makeSealedParent($branch, $opened->copy()->addHours(2));

        // First mirror — must succeed.
        $firstMirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'first counter-entry');

        $this->assertNotNull($firstMirror);
        $this->assertSame((int) $parent->id, (int) $firstMirror->parent_order_id);
        $this->assertSame(OrderStatus::RETURNED, (int) $firstMirror->status);

        // CRITICAL: parent.status is NOT mutated by the counter-entry path
        // (NF525 immutability). Verify before the second attempt so we know
        // the L73-78 guard CANNOT be what catches the duplicate.
        $parent->refresh();
        $this->assertNotSame(
            OrderStatus::RETURNED,
            (int) $parent->status,
            'NF525 invariant: counter-entry must NOT mutate parent.status — '
            . 'if this fails, the L73-78 guard would short-circuit before the '
            . 'UNIQUE catches the dupe, invalidating the heal-A.3 proof.'
        );

        // Second mirror — DB UNIQUE must reject it with SQLSTATE 23000.
        $threw = false;
        try {
            app(RefundWithCounterEntryService::class)
                ->execute($parent, 'second (duplicate) counter-entry');
        } catch (QueryException $qe) {
            $threw = true;
            $this->assertSame(
                '23000',
                $qe->errorInfo[0] ?? null,
                'UNIQUE violation must surface SQLSTATE 23000.'
            );
        }
        $this->assertTrue($threw, 'Second mirror creation must raise QueryException.');

        // DB invariant: exactly ONE mirror row exists for this parent.
        $mirrorCount = Order::withoutGlobalScopes()
            ->where('parent_order_id', $parent->id)
            ->count();
        $this->assertSame(
            1,
            $mirrorCount,
            'UNIQUE(parent_order_id) must enforce exactly one mirror per parent.'
        );
    }

    /**
     * Heal A.3 nullability sanity: multiple non-mirror orders coexist with
     * parent_order_id IS NULL (MySQL and SQLite ≥3.9 UNIQUE both allow this).
     */
    public function test_multiple_null_parent_order_id_rows_allowed(): void
    {
        $branch = Branch::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            Order::factory()->create([
                'branch_id'      => $branch->id,
                'parent_order_id' => null,
                'order_type'     => OrderType::POS,
                'status'         => OrderStatus::ACCEPT,
                'payment_status' => PaymentStatus::PAID,
                'subtotal'       => 10.00,
                'total'          => 10.00,
                'total_tax'      => 0,
                'discount'       => 0,
            ]);
        }

        $nullCount = Order::withoutGlobalScopes()->whereNull('parent_order_id')->count();
        $this->assertSame(5, $nullCount, 'UNIQUE must allow multiple NULL parent_order_id rows.');
    }

    /**
     * Heal A.3-bis: HTTP layer translates SQLSTATE 23000 into a stable 409
     * with code=MIRROR_ALREADY_EXISTS instead of a generic 500. We exercise
     * the controller path with two manual service calls + an HTTP attempt:
     *  - call 1 (service-level) creates the mirror.
     *  - call 2 (HTTP) hits PosOrderController::refundWithCounterEntry which
     *    re-enters the service, sees UNIQUE blow up, catches QueryException
     *    23000 → returns 409 + MIRROR_ALREADY_EXISTS body.
     */
    public function test_http_second_call_returns_409_mirror_already_exists(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        // Grant the permission gate guarding the route (middleware `permission:pos-orders`).
        if (!\Spatie\Permission\Models\Permission::where('name', 'pos-orders')->exists()) {
            \Spatie\Permission\Models\Permission::create(['name' => 'pos-orders', 'guard_name' => 'web']);
        }
        $admin->givePermissionTo('pos-orders');

        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch, $opened, $closed);

        Auth::setUser($admin);
        $parent = $this->makeSealedParent($branch, $opened->copy()->addHours(2));

        // First mirror via service-level (acting as Auth() admin).
        app(RefundWithCounterEntryService::class)->execute($parent, 'http-test seed mirror');

        // Second attempt via HTTP — controller must catch QueryException 23000.
        // X-Idempotency-Key REQUIRED by IdempotencyKeyMiddleware (route is on
        // config/idempotency.php required_routes list). Use a fresh distinct
        // key — the bug Z8 P0-1 describes is precisely the "two distinct
        // idempotency keys → double mirror" path, so we use a fresh key here.
        $resp = $this->actingAs($admin, 'sanctum')
            ->withHeaders([
                'X-Idempotency-Key' => 'heal-A3-test-' . bin2hex(random_bytes(8)),
            ])
            ->postJson("/api/admin/pos-order/{$parent->id}/refund-with-counter-entry", [
                'reason' => 'duplicate via HTTP — must surface 409',
            ]);

        $resp->assertStatus(409);
        $resp->assertJson([
            'success' => false,
            'code'    => 'MIRROR_ALREADY_EXISTS',
        ]);

        // DB invariant still holds — exactly one mirror.
        $mirrorCount = Order::withoutGlobalScopes()
            ->where('parent_order_id', $parent->id)
            ->count();
        $this->assertSame(1, $mirrorCount,
            'HTTP failure path must not leak a second mirror.');
    }
}
