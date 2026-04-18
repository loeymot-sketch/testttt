<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\DefaultAccess;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Tests isolation multi-branches via BranchScope
 * Vérifie que les users d'une branche ne voient pas les données des autres branches
 */
class BranchScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;

    protected Branch $branchB;

    protected User $userBranchA;

    protected User $userBranchB;

    protected User $admin;

    protected Order $orderBranchA;

    protected Order $orderBranchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        // User branch A
        $this->userBranchA = User::factory()->create([
            'branch_id' => $this->branchA->id,
        ]);
        $this->userBranchA->assignRole('POS Operator');
        $this->userBranchA->givePermissionTo('online-orders');

        // User branch B
        $this->userBranchB = User::factory()->create([
            'branch_id' => $this->branchB->id,
        ]);
        $this->userBranchB->assignRole('POS Operator');
        $this->userBranchB->givePermissionTo('online-orders');

        // Admin (branch_id = 0)
        $this->admin = User::factory()->create([
            'branch_id' => 0,
        ]);
        $this->admin->assignRole('Admin');

        // Commande branch A
        $this->orderBranchA = Order::create([
            'user_id' => $this->userBranchA->id,
            'branch_id' => $this->branchA->id,
            'order_serial_no' => 'TEST001',
            'status' => OrderStatus::ACCEPT,
            'order_type' => OrderType::POS,
            'order_datetime' => now(),
            'subtotal' => 50.00,
            'total' => 50.00,
        ]);

        // Commande branch B
        $this->orderBranchB = Order::create([
            'user_id' => $this->userBranchB->id,
            'branch_id' => $this->branchB->id,
            'order_serial_no' => 'TEST002',
            'status' => OrderStatus::ACCEPT,
            'order_type' => OrderType::POS,
            'order_datetime' => now(),
            'subtotal' => 75.00,
            'total' => 75.00,
        ]);
    }

    /**
     * Test BS-01: User branch A ne voit que les commandes de sa branche
     */
    public function test_user_branch_a_only_sees_branch_a_orders(): void
    {
        $orders = Order::all();

        // Sans authentification, BranchScope ne s'applique pas
        // Mais avec actingAs, il s'applique
        $this->actingAs($this->userBranchA);

        $orders = Order::all();

        $this->assertEquals(1, $orders->count());
        $this->assertEquals($this->orderBranchA->id, $orders->first()->id);
    }

    /**
     * Test BS-02: User branch B ne voit pas les commandes branch A
     */
    public function test_user_branch_b_cannot_see_branch_a_orders(): void
    {
        $this->actingAs($this->userBranchB);

        $orders = Order::all();

        $this->assertEquals(1, $orders->count());
        $this->assertEquals($this->orderBranchB->id, $orders->first()->id);
        $this->assertNotEquals($this->orderBranchA->id, $orders->first()->id);
    }

    /**
     * Test BS-03: Admin (branch_id=0) voit toutes les commandes
     */
    public function test_admin_sees_all_branches_orders(): void
    {
        DefaultAccess::create([
            'name' => 'branch_id',
            'user_id' => $this->admin->id,
            'default_id' => 0,
        ]);
        $this->actingAs($this->admin);

        $orders = Order::all();

        $this->assertEquals(2, $orders->count());
        $this->assertTrue($orders->contains('id', $this->orderBranchA->id));
        $this->assertTrue($orders->contains('id', $this->orderBranchB->id));
    }

    /**
     * Test BS-04: Requête avec WHERE multiple respecte BranchScope
     */
    public function test_complex_query_with_where_respects_branch_scope(): void
    {
        $this->actingAs($this->userBranchA);

        // Requête complexe: WHERE status = ACCEPT AND branch_id = A
        $orders = Order::where('status', OrderStatus::ACCEPT)->get();

        $this->assertEquals(1, $orders->count());
        $this->assertEquals($this->orderBranchA->id, $orders->first()->id);
        $this->assertEquals(OrderStatus::ACCEPT, $orders->first()->status);
    }

    /**
     * Test BS-05: Requête avec orWhere ne casse pas l'isolation
     * (BranchScope avec closure protège contre ce cas)
     */
    public function test_orwhere_does_not_break_isolation(): void
    {
        // Créer une commande avec statut différent pour branch A
        Order::create([
            'user_id' => $this->userBranchA->id,
            'branch_id' => $this->branchA->id,
            'order_serial_no' => 'TEST003',
            'status' => OrderStatus::PENDING,
            'order_type' => OrderType::POS,
            'order_datetime' => now(),
            'subtotal' => 25.00,
            'total' => 25.00,
        ]);

        $this->actingAs($this->userBranchA);

        // Requête avec orWhere — BranchScope doit encapsuler dans closure
        $orders = Order::where('status', OrderStatus::ACCEPT)
            ->orWhere('status', OrderStatus::PENDING)
            ->get();

        // Doit retourner 2 commandes (ACCEPT + PENDING) de branch A
        // Mais PAS la commande de branch B
        $this->assertEquals(2, $orders->count());
        $this->assertFalse($orders->contains('id', $this->orderBranchB->id));
    }

    /**
     * Test BS-06: [FIX-54-8] Staff ne voit pas les commandes branch_id=0 (réservé admin / FK branches)
     */
    public function test_global_records_with_branch_id_zero_are_visible(): void
    {
        if (! Branch::query()->whereKey(0)->exists()) {
            // MySQL: without NO_AUTO_VALUE_ON_ZERO, INSERT id=0 on an AUTO_INCREMENT PK
            // is rewritten to the next sequence value — FK from orders.branch_id=0 then fails.
            if (DB::getDriverName() === 'mysql') {
                DB::statement("SET SESSION sql_mode = CONCAT(TRIM(BOTH ',' FROM IFNULL(@@SESSION.sql_mode,'')), ',NO_AUTO_VALUE_ON_ZERO')");
            }
            DB::table('branches')->insert([
                'id' => 0,
                'name' => 'Virtual',
                'city' => '-',
                'state' => '-',
                'zip_code' => '0',
                'address' => '-',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $globalOrder = Order::create([
            'user_id' => $this->admin->id,
            'branch_id' => 0,
            'order_serial_no' => 'TESTGLOBAL',
            'status' => OrderStatus::ACCEPT,
            'order_type' => OrderType::POS,
            'order_datetime' => now(),
            'subtotal' => 100.00,
            'total' => 100.00,
        ]);

        $this->actingAs($this->userBranchA);

        $orders = Order::all();

        $this->assertEquals(1, $orders->count());
        $this->assertTrue($orders->contains('id', $this->orderBranchA->id));
        $this->assertFalse($orders->contains('id', $globalOrder->id));

        $this->actingAs($this->admin);
        $this->assertTrue(Order::query()->whereKey($globalOrder->id)->exists());
    }

    /**
     * Test BS-07: FrontendOrder a le même BranchScope que Order
     */
    public function test_frontend_order_has_same_branch_scope(): void
    {
        $this->actingAs($this->userBranchA);

        // FrontendOrder est un alias de Order avec BranchScope
        $frontendOrders = \App\Models\FrontendOrder::all();

        $this->assertEquals(1, $frontendOrders->count());
        $this->assertEquals($this->orderBranchA->id, $frontendOrders->first()->id);
    }

    /**
     * Test BS-08: API endpoint admin orders respecte BranchScope
     */
    public function test_api_admin_orders_respects_branch_scope(): void
    {
        $this->actingAs($this->userBranchA, 'sanctum');
        Permission::firstOrCreate(['name' => 'online-orders', 'guard_name' => 'sanctum']);
        $this->userBranchA->givePermissionTo('online-orders');

        $response = $this->withHeader('x-api-key', config('app.api_key'))->getJson('/api/admin/online-order');

        $response->assertStatus(200);

        // Vérifier que seulement la commande branch A est retournée
        $data = $response->json('data');
        $this->assertEquals(1, count($data));
        $this->assertEquals($this->orderBranchA->id, $data[0]['id']);
    }
}
