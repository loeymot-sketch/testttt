<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Models\AuditLog;
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
 * @FK-ID GOAL-2026-05-18 Impl E — Livreur P0 heal sentinel.
 *
 * @source reports/test-e2e/goal-2026-05-18/round-1/agent-7-livreur.md
 *         findings F-6.1.2 (P0), F-6.2.1 (P0), F-6.2.3 (P0), F-6.1.1 (P1).
 *
 * Verifies the 4 fixes shipped in commit fix(livreur-v1-prep):
 *
 *   • P0-LIV-01  OrderService::selectDeliveryBoy now refuses any target user
 *                that (a) lacks the DELIVERY_BOY role OR (b) belongs to a
 *                different branch than the order. Both the admin (auth=false)
 *                and customer (auth=true) paths are covered.
 *
 *   • P0-LIV-02  When a livreur transitions an order to DELIVERED and the auto
 *                cash-collected → PAID flip fires, an `delivery.cash_collected_escrow`
 *                AuditLog entry is written via AuditLogService::write. The HMAC
 *                chain is appended (NF525 chain-integrity preserved — no past
 *                hash is mutated, only one new row appended per delivery).
 *
 *   • P0-LIV-03  Before the auto cash-collected → PAID flip, the order's
 *                payment_method MUST be a known enum value. A corrupt / null /
 *                out-of-range payment_method aborts with 422 BEFORE any DB
 *                mutation — defence-in-depth against silent double-charge.
 *
 *   • P1-LIV-01  SimpleDeliveryBoyOrderResource now ships an `items` array so
 *                the livreur's `/api/frontend/delivery-boy-order` index payload
 *                tells the driver what they are carrying.
 *
 * Audit chain re-attestation: each test that exercises the escrow path also
 * asserts `audit_logs` count after = count before + N where N = number of
 * escrow writes the test triggered. The OrderService::deliveryBoyOrderChangeStatus
 * path emits exactly one `delivery.cash_collected_escrow` row per cash delivery.
 */
class DeliveryBoyHardeningSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        // [WAVE-H bug_001 follow-up — 2026-05-19]
        // Seed the 5 enum-aligned roles at EXPLICIT ids via raw DB::insert.
        // Spatie\Permission\Models\Role guards the primary key in its
        // constructor (Models/Role.php:36 → `$this->guarded[] = $this->primaryKey`)
        // so `Role::firstOrCreate(['id'=>X], [...])` silently strips the id
        // and the row lands wherever AUTO_INCREMENT chooses. The previous
        // setUp pattern was passing tests by triple-coincidence: `seedSpatieRoles`
        // planted 'Branch Manager' at id=3, the `firstOrCreate(['id'=>3])` then
        // matched (early-return, no insert), and the broken `->role(3)` in
        // selectDeliveryBoy resolved to 'Branch Manager' — never actually
        // exercising 'Delivery Boy' identification. With selectDeliveryBoy
        // healed to `->role('Delivery Boy', 'sanctum')`, the test honestly
        // needs a 'Delivery Boy' row at id=EnumRole::DELIVERY_BOY (=3).
        $now = now();
        $rolesTable = config('permission.table_names.roles', 'roles');
        DB::table($rolesTable)->insert([
            ['id' => EnumRole::ADMIN,        'name' => 'Admin',        'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
            ['id' => EnumRole::CUSTOMER,     'name' => 'Customer',     'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
            ['id' => EnumRole::DELIVERY_BOY, 'name' => 'Delivery Boy', 'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
            ['id' => EnumRole::WAITER,       'name' => 'Waiter',       'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
            ['id' => EnumRole::CHEF,         'name' => 'Chef',         'guard_name' => 'sanctum', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Now run seedSpatieRoles — it firstOrCreates Admin/Chef/Customer/etc
        // by name+guard. The first 4 already exist (no-op), and Branch Manager
        // / POS Operator / Stuff are appended at next AUTO_INCREMENT (≥6).
        $this->seedSpatieRoles();

        // Bust the Spatie permission registrar cache so it sees the raw inserts.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeDeliveryBoy(int $branchId): User
    {
        $user = User::forceCreate([
            'name' => 'Driver '.uniqid(),
            'email' => 'driver-'.uniqid().'@sentinel.test',
            'username' => 'driver_'.uniqid(),
            'password' => bcrypt('secret-passwd'),
            'branch_id' => $branchId,
            'email_verified_at' => now(),
            'status' => 1,
        ]);
        $user->assignRole(EnumRole::DELIVERY_BOY);

        return $user->fresh();
    }

    private function makeNonDeliveryBoy(int $branchId, int $roleId = EnumRole::CHEF): User
    {
        $user = User::forceCreate([
            'name' => 'NotDriver '.uniqid(),
            'email' => 'notdriver-'.uniqid().'@sentinel.test',
            'username' => 'notdriver_'.uniqid(),
            'password' => bcrypt('secret-passwd'),
            'branch_id' => $branchId,
            'email_verified_at' => now(),
            'status' => 1,
        ]);
        $user->assignRole($roleId);

        return $user->fresh();
    }

    // ───────────────────────────────────────────────────────────────────
    // P0-LIV-01 — selectDeliveryBoy multi-tenant + role isolation
    // ───────────────────────────────────────────────────────────────────

    public function test_p0_liv_01_select_delivery_boy_rejects_cross_branch_driver(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $orderInA = Order::factory()->create([
            'branch_id' => $branchA->id,
            'order_type' => OrderType::DELIVERY,
            'payment_method' => 1, // CASH_ON_DELIVERY
        ]);
        $driverInB = $this->makeDeliveryBoy($branchB->id);

        $request = Request::create('/api/admin/pos-order/select-delivery-boy/'.$orderInA->id, 'POST', [
            'delivery_boy_id' => $driverInB->id,
        ]);

        try {
            app(OrderService::class)->selectDeliveryBoy($orderInA, $request, /* auth */ false);
            $this->fail('selectDeliveryBoy must reject a cross-branch driver assignment.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), 'Cross-branch driver assign must 403.');
        }

        // Defence-in-depth — no mutation persisted.
        $this->assertNull($orderInA->fresh()->delivery_boy_id, 'delivery_boy_id must remain null.');
    }

    public function test_p0_liv_01_select_delivery_boy_rejects_non_delivery_boy_target(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'payment_method' => 1,
        ]);
        $chef = $this->makeNonDeliveryBoy($branch->id, EnumRole::CHEF);

        $request = Request::create('/api/admin/pos-order/select-delivery-boy/'.$order->id, 'POST', [
            'delivery_boy_id' => $chef->id,
        ]);

        try {
            app(OrderService::class)->selectDeliveryBoy($order, $request, /* auth */ false);
            $this->fail('selectDeliveryBoy must reject a non-delivery-boy target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), 'Non-delivery-boy target must 403.');
        }

        $this->assertNull($order->fresh()->delivery_boy_id);
    }

    public function test_p0_liv_01_select_delivery_boy_allows_same_branch_driver(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'payment_method' => 1,
        ]);
        $driver = $this->makeDeliveryBoy($branch->id);

        $request = Request::create('/api/admin/pos-order/select-delivery-boy/'.$order->id, 'POST', [
            'delivery_boy_id' => $driver->id,
        ]);

        $assignBefore = AuditLog::query()->where('action', 'order.delivery_boy_assigned')->count();

        $result = app(OrderService::class)->selectDeliveryBoy($order, $request, /* auth */ false);

        $this->assertSame((int) $driver->id, (int) $order->fresh()->delivery_boy_id);
        $this->assertNotNull($result);

        // [P0-LIV-01 trace symmetry] Audit-log row written for the assignment.
        $assignAfter = AuditLog::query()->where('action', 'order.delivery_boy_assigned')->count();
        $this->assertSame($assignBefore + 1, $assignAfter, 'Driver assignment must append one audit_log row.');

        $row = AuditLog::query()
            ->where('action', 'order.delivery_boy_assigned')
            ->where('resource_id', $order->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('admin_assign', $row->payload['path']);
        $this->assertSame((int) $driver->id, (int) $row->payload['delivery_boy_id']);
    }

    public function test_p0_liv_01_select_delivery_boy_customer_self_service_happy_path(): void
    {
        // $auth=true path: customer-facing selectDeliveryBoy. Even on the
        // customer route, the same role + same-branch guard applies — a
        // customer cannot escape the multi-tenant fence either.
        $branch = Branch::factory()->create();
        $customer = $this->makeNonDeliveryBoy($branch->id, EnumRole::CUSTOMER);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $customer->id, // ownership check applies in $auth=true path
            'order_type' => OrderType::DELIVERY,
            'payment_method' => 1,
        ]);
        $driver = $this->makeDeliveryBoy($branch->id);

        $this->actingAs($customer, 'sanctum');

        $request = Request::create('/api/customer/order/'.$order->id.'/select-delivery-boy', 'POST', [
            'delivery_boy_id' => $driver->id,
        ]);

        $assignBefore = AuditLog::query()->where('action', 'order.delivery_boy_assigned')->count();

        $result = app(OrderService::class)->selectDeliveryBoy($order, $request, /* auth */ true);

        $this->assertSame((int) $driver->id, (int) $order->fresh()->delivery_boy_id);
        $this->assertNotNull($result);

        $assignAfter = AuditLog::query()->where('action', 'order.delivery_boy_assigned')->count();
        $this->assertSame($assignBefore + 1, $assignAfter);

        $row = AuditLog::query()
            ->where('action', 'order.delivery_boy_assigned')
            ->where('resource_id', $order->id)
            ->first();
        $this->assertSame('customer_self_service', $row->payload['path']);
    }

    public function test_p0_liv_01_select_delivery_boy_customer_self_service_rejects_other_customers_order(): void
    {
        $branch = Branch::factory()->create();
        $owner = $this->makeNonDeliveryBoy($branch->id, EnumRole::CUSTOMER);
        $attacker = $this->makeNonDeliveryBoy($branch->id, EnumRole::CUSTOMER);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $owner->id,
            'order_type' => OrderType::DELIVERY,
            'payment_method' => 1,
        ]);
        $driver = $this->makeDeliveryBoy($branch->id);

        $this->actingAs($attacker, 'sanctum');

        $request = Request::create('/api/customer/order/'.$order->id.'/select-delivery-boy', 'POST', [
            'delivery_boy_id' => $driver->id,
        ]);

        try {
            app(OrderService::class)->selectDeliveryBoy($order, $request, /* auth */ true);
            $this->fail('Attacker must not be allowed to assign a driver to another customer\'s order.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertNull($order->fresh()->delivery_boy_id);
    }

    public function test_p0_liv_01_select_delivery_boy_rejects_missing_target_id(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'payment_method' => 1,
        ]);
        $request = Request::create('/api/admin/pos-order/select-delivery-boy/'.$order->id, 'POST', [
            // delivery_boy_id absent on purpose — must abort, not silently null-out the FK.
        ]);

        try {
            app(OrderService::class)->selectDeliveryBoy($order, $request, /* auth */ false);
            $this->fail('selectDeliveryBoy must reject a missing delivery_boy_id.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode(), 'Missing delivery_boy_id must 422.');
        } catch (\Exception $e) {
            // The service wraps validation throws — accept both 422 HttpException
            // and the generic Exception(422) path used elsewhere in the service.
            $this->assertSame(422, $e->getCode());
        }

        $this->assertNull($order->fresh()->delivery_boy_id);
    }

    // ───────────────────────────────────────────────────────────────────
    // P0-LIV-02 — Cash collection on delivery writes an escrow audit_log
    // ───────────────────────────────────────────────────────────────────

    public function test_p0_liv_02_cash_delivery_writes_escrow_audit_log(): void
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'delivery_boy_id' => $driver->id,
            'status' => OrderStatus::OUT_FOR_DELIVERY,
            'payment_method' => 1, // CASH_ON_DELIVERY
            'payment_status' => PaymentStatus::UNPAID,
            'total' => 27.50,
        ]);

        // Audit baseline BEFORE the transition.
        $before = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->count();

        $this->actingAs($driver, 'sanctum');

        $request = Request::create('/api/frontend/delivery-boy-order/change-status/'.$order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);

        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $request);

        // Exactly ONE escrow row appended for this order.
        $after = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->count();
        $this->assertSame(
            $before + 1,
            $after,
            'Cash collection at doorstep must append exactly one escrow audit_log row (NF525).'
        );

        $row = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->where('resource_id', $order->id)
            ->first();
        $this->assertNotNull($row, 'Escrow row must be findable by order resource_id.');
        $this->assertSame((int) $branch->id, (int) $row->branch_id, 'Escrow row scoped to branch.');
        $this->assertSame((int) $driver->id, (int) $row->user_id, 'Escrow row attributes driver.');
        $this->assertSame('order', $row->resource);
        $this->assertIsArray($row->payload);
        $this->assertArrayHasKey('amount_collected', $row->payload);
        $this->assertSame(27.50, (float) $row->payload['amount_collected']);
        $this->assertSame((int) $driver->id, (int) $row->payload['delivery_boy_id']);
        $this->assertSame(1, (int) $row->payload['payment_method']);

        // Order DELIVERED + flipped to PAID.
        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $fresh->status);
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
    }

    /**
     * [SELF-AUDIT R2 P1 2026-07-05 — vente livraison COD off-book NF525] Le flip UNPAID→PAID au doorstep
     * (deliveryBoyOrderChangeStatus) scellait la commande en PAID SANS allouer de fiscal_sequence_no →
     * vente PAYÉE hors chaîne NF525 (exclue du Z signé, jamais rattrapée). Miroir du fix Wave-2. Ce test
     * verrouille : une commande COD arrivée à DELIVERED reçoit un numéro fiscal (scellage).
     */
    public function test_cod_delivery_at_delivered_seals_fiscal_sequence_nf525(): void
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'delivery_boy_id' => $driver->id,
            'status' => OrderStatus::OUT_FOR_DELIVERY,
            'payment_method' => 1, // CASH_ON_DELIVERY
            'payment_status' => PaymentStatus::UNPAID,
            'fiscal_sequence_no' => null,
            'total' => 27.50,
        ]);
        $this->assertNull($order->fiscal_sequence_no, 'Précondition : commande COD non scellée avant livraison.');

        $this->actingAs($driver, 'sanctum');
        $request = Request::create('/api/frontend/delivery-boy-order/change-status/'.$order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $request);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status, 'Cash encaissé au doorstep.');
        $this->assertNotNull(
            $fresh->fiscal_sequence_no,
            'Une vente COD PAYÉE DOIT être scellée dans la chaîne NF525 (fiscal_sequence_no alloué) — sinon vente hors Z signé.'
        );
        $this->assertGreaterThan(0, (int) $fresh->fiscal_sequence_no);
    }

    public function test_p0_liv_02_no_escrow_when_already_paid_app_prepaid(): void
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'delivery_boy_id' => $driver->id,
            'status' => OrderStatus::OUT_FOR_DELIVERY,
            'payment_method' => 4, // CARD (app pre-paid via Stripe)
            'payment_status' => PaymentStatus::PAID,
            'total' => 19.90,
        ]);

        $before = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->count();

        $this->actingAs($driver, 'sanctum');

        $request = Request::create('/api/frontend/delivery-boy-order/change-status/'.$order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);

        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $request);

        $after = AuditLog::query()
            ->where('action', 'delivery.cash_collected_escrow')
            ->count();
        $this->assertSame(
            $before,
            $after,
            'App-prepaid (already PAID, no cash) must NOT write an escrow row.'
        );
    }

    // ───────────────────────────────────────────────────────────────────
    // P0-LIV-03 — payment_method whitelist guard against double-charge
    // ───────────────────────────────────────────────────────────────────

    public function test_p0_liv_03_rejects_corrupt_payment_method_before_paid_flip(): void
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);

        // Corrupt payment_method = 99 (out of PaymentGateway enum range 1..5).
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'delivery_boy_id' => $driver->id,
            'status' => OrderStatus::OUT_FOR_DELIVERY,
            'payment_method' => 99,
            'payment_status' => PaymentStatus::UNPAID,
            'total' => 15.00,
        ]);

        $this->actingAs($driver, 'sanctum');
        $request = Request::create('/api/frontend/delivery-boy-order/change-status/'.$order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);

        try {
            app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $request);
            $this->fail('Corrupt payment_method must abort the DELIVERED transition.');
        } catch (\Throwable $e) {
            $this->assertSame(
                422,
                $e instanceof HttpException ? $e->getStatusCode() : $e->getCode(),
                'Corrupt payment_method must produce a 422.'
            );
        }

        // NO double charge: payment_status untouched, order still OUT_FOR_DELIVERY.
        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status);
        $this->assertSame(OrderStatus::OUT_FOR_DELIVERY, (int) $fresh->status);
    }

    public function test_p0_liv_03_rejects_zero_payment_method_before_paid_flip(): void
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);

        // Zero is not a valid PaymentGateway constant (range 1..5). Although the
        // orders.payment_method column has a non-null default, a corrupted client
        // could still POST zero / negative integers — guard must reject.
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'delivery_boy_id' => $driver->id,
            'status' => OrderStatus::OUT_FOR_DELIVERY,
            'payment_method' => 0,
            'payment_status' => PaymentStatus::UNPAID,
            'total' => 15.00,
        ]);

        $this->actingAs($driver, 'sanctum');
        $request = Request::create('/api/frontend/delivery-boy-order/change-status/'.$order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);

        try {
            app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $request);
            $this->fail('Zero / out-of-range payment_method must abort the DELIVERED transition.');
        } catch (\Throwable $e) {
            $this->assertSame(
                422,
                $e instanceof HttpException ? $e->getStatusCode() : $e->getCode()
            );
        }

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status);
    }

    // ───────────────────────────────────────────────────────────────────
    // P1-LIV-01 — index payload includes order items
    // ───────────────────────────────────────────────────────────────────

    public function test_p1_liv_01_simple_delivery_boy_order_resource_includes_items(): void
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'delivery_boy_id' => $driver->id,
            'status' => OrderStatus::OUT_FOR_DELIVERY,
            'payment_method' => 1,
            'payment_status' => PaymentStatus::UNPAID,
        ]);

        // Seed 2 real items (FK item_id → items) + their order_items rows.
        $burger = \App\Models\Item::factory()->create(['name' => 'Burger Test']);
        $fries = \App\Models\Item::factory()->create(['name' => 'Frites Test']);

        \App\Models\OrderItem::query()->insert([
            [
                'order_id' => $order->id,
                'item_id' => $burger->id,
                'branch_id' => $branch->id,
                'price' => 5.0,
                'quantity' => 2,
                'discount' => 0.0,
                'total_price' => 10.0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'order_id' => $order->id,
                'item_id' => $fries->id,
                'branch_id' => $branch->id,
                'price' => 7.5,
                'quantity' => 1,
                'discount' => 0.0,
                'total_price' => 7.5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $order->load('orderItems');
        $resource = new \App\Http\Resources\SimpleDeliveryBoyOrderResource($order);
        $payload = $resource->toArray(Request::create('/'));

        $this->assertArrayHasKey('items', $payload, 'Livreur index payload MUST expose an items array (P1-LIV-01).');
        $this->assertIsArray($payload['items']);
        $this->assertCount(2, $payload['items'], 'Items array must contain both seeded order_items.');

        // Each item entry must carry the livreur-relevant fields.
        foreach ($payload['items'] as $line) {
            $this->assertArrayHasKey('quantity', $line);
            $this->assertArrayHasKey('item_id', $line);
        }
    }
}
