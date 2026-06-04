<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderType;
use App\Enums\Role as EnumRole;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * @FK-ID WAVE-H bug_001 — heal selectDeliveryBoy role lookup.
 * @source reports/audit/wave-h-2026-05-19/WH-1-bug001-SELECT-DELIVERY-BOY-ROLE/STATUS.md
 *
 * Sentinel for the heal mirroring the GOAL-PAGEBY-STOCK-2026-05-18 wave that
 * fixed `->role($int)` across 5 sibling services (DeliveryBoyService:45,
 * AdministratorService:46, ChefService:43, CustomerService:43, WaiterService:44).
 *
 * The bug:
 *   `User::...->role(\App\Enums\Role::DELIVERY_BOY)` passes the integer `3`
 *   to Spatie's `scopeRole` (HasRoles trait L73-94). Because `is_numeric($role)`
 *   is true, Spatie dispatches to `findById(3, $guard)`. On fresh-seeded envs
 *   the `roles` table's AUTO_INCREMENT can skip past 3 (lands at 73-80) when
 *   prior transactions rolled back — `findById(3, ...)` then throws
 *   `RoleDoesNotExist` (or the resulting scope filters to empty), so every
 *   legitimate driver assignment 403/500s. The stable identity is the role
 *   NAME, not the legacy enum integer — see `database/seeders/SpatieRoleLookup.php`
 *   for the same rationale.
 *
 * The existing `DeliveryBoyHardeningSentinelTest` accidentally MASKS this bug
 * because its setUp pre-seeds `Role::firstOrCreate(['id' => 3], ...)` —
 * aligning the row to the enum value the broken code expects. THIS sentinel
 * deliberately forces 'Delivery Boy' onto an id != 3 to surface the live
 * failure mode on a production-style fresh seed.
 */
class SelectDeliveryBoyRoleByNameSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The non-3 id we force 'Delivery Boy' onto. Mirrors the comment at
     * `app/Services/DeliveryBoyService.php:42-44` ("fresh seed often lands
     * at id=73-76") so the sentinel intent reads directly off the heal note.
     */
    private const FORCED_DRIVER_ROLE_ID = 73;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        // [SENTINEL bug_001 — discriminator] We must land 'Delivery Boy' on an
        // id != EnumRole::DELIVERY_BOY (=3) so the broken code's
        // `findById(3, 'sanctum')` either throws RoleDoesNotExist or matches
        // a non-driver row. Spatie\Permission\Models\Role guards the primary
        // key in its constructor (line 36: `$this->guarded[] = $this->primaryKey`),
        // so passing explicit `id` through Role::create / firstOrCreate is silently
        // stripped — the row lands wherever AUTO_INCREMENT chooses. We bypass the
        // guard via DB::table::insert which writes the literal id we provide.
        $now = now();
        $rolesTable = config('permission.table_names.roles', 'roles');
        DB::table($rolesTable)->insert([
            ['id' => 1,  'name' => 'Admin',        'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2,  'name' => 'Customer',     'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
            // [SENTINEL bug_001] Intentionally NO row at id=3 — mimics a fresh
            // seed where AUTO_INCREMENT has skipped past EnumRole::DELIVERY_BOY.
            ['id' => 4,  'name' => 'Waiter',       'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 5,  'name' => 'Chef',         'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
            // 'Delivery Boy' lands at id=73 — the value cited in the heal
            // comments at app/Services/DeliveryBoyService.php:42-44 as a
            // realistic post-rollback AUTO_INCREMENT outcome.
            ['id' => self::FORCED_DRIVER_ROLE_ID, 'name' => 'Delivery Boy', 'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Bust the Spatie permission registrar cache so it sees the raw inserts.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeDriver(int $branchId): User
    {
        $user = User::forceCreate([
            'name'              => 'Driver ' . uniqid(),
            'email'             => 'driver-' . uniqid() . '@sentinel.test',
            'username'          => 'driver_' . uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => $branchId,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        // assignRole by NAME — does not depend on the legacy enum integer.
        $user->assignRole('Delivery Boy');
        return $user->fresh();
    }

    private function makeChef(int $branchId): User
    {
        $user = User::forceCreate([
            'name'              => 'Chef ' . uniqid(),
            'email'             => 'chef-' . uniqid() . '@sentinel.test',
            'username'          => 'chef_' . uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => $branchId,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        $user->assignRole('Chef');
        return $user->fresh();
    }

    // ───────────────────────────────────────────────────────────────────
    // Sanity — document WHY this sentinel exists (id != legacy enum)
    // ───────────────────────────────────────────────────────────────────

    public function test_sentinel_setup_forces_delivery_boy_role_off_legacy_enum_id(): void
    {
        $role = Role::where('name', 'Delivery Boy')
            ->where('guard_name', 'sanctum')
            ->first();

        $this->assertNotNull($role, 'Delivery Boy role must be seeded for the sentinel.');
        $this->assertNotSame(
            EnumRole::DELIVERY_BOY,
            (int) $role->id,
            'This sentinel is meaningless unless Delivery Boy.id != EnumRole::DELIVERY_BOY. '
            . 'Adjust setUp() decoys if the schema/IDs drift.'
        );
        $this->assertSame(self::FORCED_DRIVER_ROLE_ID, (int) $role->id);
    }

    // ───────────────────────────────────────────────────────────────────
    // CORE — fresh-seed scenario where roles.id=3 doesn't exist
    // ───────────────────────────────────────────────────────────────────

    public function test_select_delivery_boy_succeeds_when_role_id_skipped_past_legacy_enum(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::DELIVERY,
            'payment_method' => 1,
        ]);
        $driver = $this->makeDriver($branch->id);

        $request = Request::create('/api/admin/pos-order/select-delivery-boy/' . $order->id, 'POST', [
            'delivery_boy_id' => $driver->id,
        ]);

        // Outcome-agnostic assertion (cf. advisor): regardless of WHICH error
        // path the broken code takes (RoleDoesNotExist throw vs. empty scope
        // → null target → 403), the assignment must NOT land. Post-fix the
        // assignment lands and `delivery_boy_id` matches the driver.
        app(OrderService::class)->selectDeliveryBoy($order, $request, /* auth */ false);

        $this->assertSame(
            (int) $driver->id,
            (int) $order->fresh()->delivery_boy_id,
            'After the heal, selectDeliveryBoy must succeed even when Spatie roles.id '
            . 'has skipped past App\\Enums\\Role::DELIVERY_BOY (=3).'
        );
    }

    // ───────────────────────────────────────────────────────────────────
    // Negative — target with wrong role still rejected (regression guard)
    // ───────────────────────────────────────────────────────────────────

    public function test_select_delivery_boy_still_rejects_non_driver_target_post_heal(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::DELIVERY,
            'payment_method' => 1,
        ]);
        $chef = $this->makeChef($branch->id);

        $request = Request::create('/api/admin/pos-order/select-delivery-boy/' . $order->id, 'POST', [
            'delivery_boy_id' => $chef->id,
        ]);

        try {
            app(OrderService::class)->selectDeliveryBoy($order, $request, /* auth */ false);
            $this->fail('Heal must not weaken the role guard — non-driver target must still 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('delivery boy', strtolower($e->getMessage()));
        }

        $this->assertNull(
            $order->fresh()->delivery_boy_id,
            'No mutation may persist when the role guard rejects the target.'
        );
    }

    // ───────────────────────────────────────────────────────────────────
    // Happy path — same-branch, valid driver (smoke mirror of sibling test)
    // ───────────────────────────────────────────────────────────────────

    public function test_select_delivery_boy_happy_path_with_skipped_role_id(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::DELIVERY,
            'payment_method' => 1,
        ]);
        $driver = $this->makeDriver($branch->id);

        $request = Request::create('/api/admin/pos-order/select-delivery-boy/' . $order->id, 'POST', [
            'delivery_boy_id' => $driver->id,
        ]);

        $result = app(OrderService::class)->selectDeliveryBoy($order, $request, /* auth */ false);

        $this->assertNotNull($result);
        $this->assertSame((int) $driver->id, (int) $order->fresh()->delivery_boy_id);
    }
}
