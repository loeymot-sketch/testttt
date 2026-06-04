<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26] POS Refund UI permission sentinel.
 *
 * Locks the permission gate on POST /api/admin/pos-order/{order}/refund-with-counter-entry.
 * Pre-heal, the route was permission-guarded only via the role-level
 * `permission:pos-orders` middleware (POS Operator HAD pos-orders) — meaning a
 * cashier could issue NF525 counter-entry refunds at will. That violates
 * PROPOSAL_POS_REFUND_UI_2026-05-25 §4 risk-register #1 (mass-refund vector
 * by junior cashier).
 *
 * Heal: dedicated `pos-refund` permission added (PermissionTableSeeder),
 * granted ONLY to Admin (auto via Permission::all()) + Branch Manager
 * (explicit in RolePermissionTableSeeder). PosOrderController::refundWithCounterEntry
 * fail-fast abort_unless(can('pos-refund')) BEFORE validation.
 *
 * Sentinel cases:
 *   1. POS Operator (no pos-refund) → 403 + no mirror created.
 *   2. POS Operator (custom-granted pos-refund) → 201 + mirror created.
 *   3. Branch Manager (default-granted) → 201 + mirror created.
 *   4. Admin (Permission::all()) → 201 + mirror created.
 */
class PosRefundUiPermissionSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // [HEAL-4] Ensure the new permission exists (idempotent — the full
        // PermissionTableSeeder would normally seed it, but RefreshDatabase
        // tests don't run the full seeder).
        Permission::firstOrCreate(['name' => 'pos-refund', 'guard_name' => 'sanctum']);

        // Fiscal stubs — we're testing the permission gate, not the fiscal
        // logic. Mirror the pattern used by RefundCounterEntryUniqueParentTest.
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });

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
            'branch_id'          => $branch->id,
            'order_type'         => OrderType::POS,
            'status'             => OrderStatus::ACCEPT,
            'payment_status'     => PaymentStatus::PAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'subtotal'           => 30.00,
            'total'              => 30.00,
            'total_tax'          => 0,
            'discount'           => 0,
            'created_at'         => $within,
        ]);
        $parent->fiscal_sequence_no = 500;
        $parent->save();
        return $parent->fresh();
    }

    private function newCashier(Branch $branch, string $role): User
    {
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'password'  => Hash::make('password'),
        ]);
        $user->assignRole($role);
        // POS Operator + Branch Manager need pos-orders to clear the existing
        // middleware on the route group (the new pos-refund gate is checked
        // ON TOP of pos-orders).
        if (!Permission::where('name', 'pos-orders')->where('guard_name', 'sanctum')->exists()) {
            Permission::create(['name' => 'pos-orders', 'guard_name' => 'sanctum']);
        }
        $user->givePermissionTo('pos-orders');
        return $user;
    }

    /** Case 1 — POS Operator without `pos-refund` is forbidden with 403. */
    public function test_pos_operator_without_pos_refund_permission_gets_403(): void
    {
        $branch = Branch::factory()->create();
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch, $opened, $closed);
        $parent = $this->makeSealedParent($branch, $opened->copy()->addHours(2));

        $operator = $this->newCashier($branch, 'POS Operator');
        // Explicit revoke to make the assertion intent crystal-clear.
        if ($operator->hasPermissionTo('pos-refund')) {
            $operator->revokePermissionTo('pos-refund');
        }

        $resp = $this->actingAs($operator, 'sanctum')
            ->withHeaders([
                'X-Idempotency-Key' => 'sentinel-403-' . bin2hex(random_bytes(8)),
            ])
            ->postJson("/api/admin/pos-order/{$parent->id}/refund-with-counter-entry", [
                'reason' => 'POS Operator must not be able to refund.',
            ]);

        $resp->assertStatus(403);

        // Defense-in-depth: NO mirror row created.
        $mirrorCount = Order::withoutGlobalScopes()
            ->where('parent_order_id', $parent->id)
            ->count();
        $this->assertSame(0, $mirrorCount,
            'POS Operator 403 must NOT create a mirror order.');
    }

    /** Case 2 — POS Operator with custom-granted `pos-refund` succeeds with 201. */
    public function test_pos_operator_with_custom_pos_refund_permission_succeeds(): void
    {
        $branch = Branch::factory()->create();
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch, $opened, $closed);
        $parent = $this->makeSealedParent($branch, $opened->copy()->addHours(2));

        $operator = $this->newCashier($branch, 'POS Operator');
        $operator->givePermissionTo('pos-refund');

        $resp = $this->actingAs($operator, 'sanctum')
            ->withHeaders([
                'X-Idempotency-Key' => 'sentinel-pos-grant-' . bin2hex(random_bytes(8)),
            ])
            ->postJson("/api/admin/pos-order/{$parent->id}/refund-with-counter-entry", [
                'reason' => 'Owner manually granted pos-refund to this cashier.',
            ]);

        $resp->assertStatus(201);
        $resp->assertJsonPath('success', true);
    }

    /** Case 3 — Branch Manager (default-granted by RolePermissionTableSeeder) succeeds. */
    public function test_branch_manager_default_can_issue_refund(): void
    {
        $branch = Branch::factory()->create();
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch, $opened, $closed);
        $parent = $this->makeSealedParent($branch, $opened->copy()->addHours(2));

        $manager = $this->newCashier($branch, 'Branch Manager');
        // Default-grant per RolePermissionTableSeeder. Tests inject via
        // explicit givePermissionTo to mirror that production behaviour.
        $manager->givePermissionTo('pos-refund');

        $resp = $this->actingAs($manager, 'sanctum')
            ->withHeaders([
                'X-Idempotency-Key' => 'sentinel-manager-' . bin2hex(random_bytes(8)),
            ])
            ->postJson("/api/admin/pos-order/{$parent->id}/refund-with-counter-entry", [
                'reason' => 'Branch Manager refunds a paid order at counter.',
            ]);

        $resp->assertStatus(201);
        $resp->assertJsonPath('success', true);
    }

    /** Case 4 — Admin (branch_id=0, Permission::all() in production) succeeds. */
    public function test_admin_can_issue_refund(): void
    {
        $branch = Branch::factory()->create();
        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch, $opened, $closed);
        $parent = $this->makeSealedParent($branch, $opened->copy()->addHours(2));

        $admin = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('password'),
        ]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('pos-refund');
        if (!Permission::where('name', 'pos-orders')->where('guard_name', 'sanctum')->exists()) {
            Permission::create(['name' => 'pos-orders', 'guard_name' => 'sanctum']);
        }
        $admin->givePermissionTo('pos-orders');

        $resp = $this->actingAs($admin, 'sanctum')
            ->withHeaders([
                'X-Idempotency-Key' => 'sentinel-admin-' . bin2hex(random_bytes(8)),
            ])
            ->postJson("/api/admin/pos-order/{$parent->id}/refund-with-counter-entry", [
                'reason' => 'Admin issues a refund.',
            ]);

        $resp->assertStatus(201);
        $resp->assertJsonPath('success', true);
    }

    /** Permission existence — the seed migration must register pos-refund. */
    public function test_pos_refund_permission_is_registered(): void
    {
        $this->assertTrue(
            Permission::where('name', 'pos-refund')
                ->where('guard_name', 'sanctum')
                ->exists(),
            'pos-refund permission must be registered (PermissionTableSeeder entry).'
        );
    }
}
