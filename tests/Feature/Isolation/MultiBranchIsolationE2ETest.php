<?php

namespace Tests\Feature\Isolation;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\DefaultAccess;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * @FK-ID F-017 Suite 8 — Multi-Branche Isolation E2E (PHPUnit volet)
 * @source plans/PLAN_AUDIT_F017_MASSIVE_E2E_TEST_SUITE_2026-05-08.md §1 Suite 8 (lignes 206-218)
 * @sprint S5+ — gate avant merge prod
 * @severity P0 — toute fuite cross-branch est non-négociable
 *
 * Couvre les 8 scénarios du plan, volet backend / API :
 *
 * | # | Scénario | Type d'épreuve |
 * |---|----------|----------------|
 * | 1 | Cashier branch A login → ne voit que orders branch A | BranchScope eloquent + API index |
 * | 2 | Cashier branch A → GET /api/admin/pos-order/show/{id_B} → 403/404 | HTTP guard |
 * | 3 | Cashier branch A → POST change-status/{id_B} → 403/404 | HTTP guard mutation |
 * | 4 | Admin (branch_id=0 + DefaultAccess) voit toutes les branches | scope override |
 * | 5 | Kiosk branch A token → cross-branch reconcile rejected | F-008 cross-branch guard |
 * | 6 | Toggle availability branch A ne broadcast pas branch B | (validé statiquement par broadcast channel scope dans F-016 plan) |
 * | 7 | Z report branch A ≠ Z report branch B | data isolation (no cross-rows) |
 * | 8 | Cash drawer session branch A ≠ branch B | F-003 isolation |
 *
 * Volet Playwright (UI cross-tab) : `tests/e2e/multi-branch-isolation.spec.js`.
 *
 * Pattern emprunté à `BranchIsolationTest` (POS-9.1.3) — étend pour couvrir
 * les surfaces NF525 + cash sessions + kiosk reconcile.
 */
class MultiBranchIsolationE2ETest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;
    protected User $cashierA;
    protected User $cashierB;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        $this->cashierA = User::factory()->create([
            'branch_id' => $this->branchA->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->cashierA->assignRole('POS Operator');

        $this->cashierB = User::factory()->create([
            'branch_id' => $this->branchB->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->cashierB->assignRole('POS Operator');

        $this->admin = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('pwd'),
        ]);
        $this->admin->assignRole('Admin');
    }

    private function makeOrder(int $branchId, int $status = OrderStatus::PENDING, int $paymentStatus = PaymentStatus::UNPAID): Order
    {
        return Order::factory()->create([
            'branch_id'      => $branchId,
            'order_type'     => OrderType::POS,
            'status'         => $status,
            'payment_status' => $paymentStatus,
            'total'          => 12.50,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 1 — Cashier branch A login → ne voit que orders branch A   */
    /* ------------------------------------------------------------------ */
    public function test_S1_cashier_branch_a_only_sees_branch_a_orders(): void
    {
        $orderA = $this->makeOrder($this->branchA->id);
        $orderB = $this->makeOrder($this->branchB->id);

        $this->actingAs($this->cashierA, 'sanctum');
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-order');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains((int) $orderA->id, $ids, 'S1: own-branch order must be visible.');
        $this->assertNotContains((int) $orderB->id, $ids, 'S1: foreign-branch order leaked to index.');
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 2 — Cross-branch GET show/{id} → 403 ou 404                */
    /* ------------------------------------------------------------------ */
    public function test_S2_cross_branch_show_returns_403_or_404(): void
    {
        $orderB = $this->makeOrder($this->branchB->id);
        $this->actingAs($this->cashierA, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-order/show/' . $orderB->id);

        $this->assertContains(
            $response->status(),
            [403, 404],
            'S2: cross-branch GET pos-order/show must return 403 or 404, got: ' . $response->status()
        );
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 3 — Cross-branch POST change-status → 403 ou 404           */
    /* ------------------------------------------------------------------ */
    public function test_S3_cross_branch_change_status_returns_403_or_404(): void
    {
        $orderB = $this->makeOrder($this->branchB->id);
        $this->actingAs($this->cashierA, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos-order/change-status/' . $orderB->id, [
                'status' => OrderStatus::ACCEPT,
            ]);

        $this->assertContains(
            $response->status(),
            [403, 404],
            'S3: cross-branch change-status must return 403 or 404, got: ' . $response->status()
        );

        // Defensive : the order must NOT have been mutated.
        $this->assertDatabaseHas('orders', [
            'id'     => $orderB->id,
            'status' => OrderStatus::PENDING,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 4 — Admin voit toutes les branches                          */
    /* ------------------------------------------------------------------ */
    public function test_S4_admin_with_default_access_sees_all_branches(): void
    {
        $orderA = $this->makeOrder($this->branchA->id);
        $orderB = $this->makeOrder($this->branchB->id);

        DefaultAccess::create([
            'name'       => 'branch_id',
            'user_id'    => $this->admin->id,
            'default_id' => 0,
        ]);

        $this->actingAs($this->admin, 'sanctum');
        Permission::firstOrCreate(['name' => 'pos', 'guard_name' => 'sanctum']);
        $this->admin->givePermissionTo('pos');

        $orders = Order::query()->whereIn('id', [$orderA->id, $orderB->id])->get();

        $this->assertSame(2, $orders->count(), 'S4: admin (branch_id=0) must see all branches.');
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 5 — Kiosk branch A token : cross-branch reconcile rejected */
    /* (cf. F-008 PaymentReconcileController : log warning + status=unauthorized) */
    /* ------------------------------------------------------------------ */
    public function test_S5_kiosk_branch_a_cannot_reconcile_branch_b_order(): void
    {
        // Kiosk machine on branch A
        $kioskUserA = User::factory()->create(['branch_id' => $this->branchA->id]);
        KioskMachine::query()->create([
            'name'       => 'TEST-KIOSK-ISOL-' . uniqid(),
            'machine_id' => 'KM-' . uniqid(),
            'username'   => 'kiosk-isol-' . uniqid(),
            'password'   => 'pwd',
            'user_id'    => $kioskUserA->id,
            'branch_id'  => $this->branchA->id,
        ]);
        Sanctum::actingAs($kioskUserA, ['kiosk:order']);

        // Order belongs to branch B
        $orderB = FrontendOrder::query()->create([
            'user_id'          => $this->cashierB->id,
            'branch_id'        => $this->branchB->id,
            'total'            => 50.00,
            'subtotal'         => 50.00,
            'discount'         => 0,
            'payment_status'   => PaymentStatus::UNPAID,
            'payment_method'   => PaymentGateway::CARD,
            'status'           => OrderStatus::PENDING,
            'order_type'       => OrderType::KIOSK,
            'order_datetime'   => now(),
            'preparation_time' => 15,
        ]);

        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id'       => $orderB->id,
                'transaction_id' => 'TX-CROSS-BRANCH-' . uniqid(),
                'amount_cents'   => 5000,
                'card_type'      => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ]);

        $response->assertStatus(200);
        $this->assertSame(
            'unauthorized',
            $response->json('data.0.status'),
            'S5: cross-branch reconcile must return status=unauthorized (PaymentReconcileController guard).'
        );

        // Defensive : foreign order must NOT be promoted.
        $this->assertSame(
            PaymentStatus::UNPAID,
            $orderB->fresh()->payment_status,
            'S5: cross-branch reconcile must NOT mutate foreign-branch order payment_status.'
        );

        // Defensive : no audit row written for the rejected cross-branch tx.
        $this->assertSame(
            0,
            DB::table('pending_payment_confirmations')
                ->where('transaction_id', 'like', 'TX-CROSS-BRANCH-%')
                ->count(),
            'S5: cross-branch reconcile must NOT persist an audit row.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 7 — Z report branch A ≠ Z report branch B (data isolation) */
    /* (Tested via underlying orders : a Z aggregate over branch A must    */
    /*  exclude branch B orders. We assert at the data layer since         */
    /*  Z report API requires fiscal seeds out-of-scope here.)             */
    /* ------------------------------------------------------------------ */
    public function test_S7_orders_grouped_by_branch_have_disjoint_sets(): void
    {
        $orderA1 = $this->makeOrder($this->branchA->id, OrderStatus::DELIVERED, PaymentStatus::PAID);
        $orderA2 = $this->makeOrder($this->branchA->id, OrderStatus::DELIVERED, PaymentStatus::PAID);
        $orderB1 = $this->makeOrder($this->branchB->id, OrderStatus::DELIVERED, PaymentStatus::PAID);

        // BranchScope : cashier A perspective
        $this->actingAs($this->cashierA);
        $branchAOrders = Order::query()->where('payment_status', PaymentStatus::PAID)->get();

        $this->assertEqualsCanonicalizing(
            [$orderA1->id, $orderA2->id],
            $branchAOrders->pluck('id')->all(),
            'S7: branch A perspective must exclude branch B orders entirely (Z aggregate isolation).'
        );

        // BranchScope : cashier B perspective
        $this->actingAs($this->cashierB);
        $branchBOrders = Order::query()->where('payment_status', PaymentStatus::PAID)->get();

        $this->assertEqualsCanonicalizing(
            [$orderB1->id],
            $branchBOrders->pluck('id')->all(),
            'S7: branch B perspective must only see its own orders.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 8 — Cash drawer session branch A ≠ branch B (F-003)        */
    /* ------------------------------------------------------------------ */
    public function test_S8_cash_drawer_session_isolated_per_branch(): void
    {
        $cashService = app(CashDrawerService::class);

        $sessionA = $cashService->openSession($this->branchA->id, $this->cashierA->id, 100.00);
        $sessionB = $cashService->openSession($this->branchB->id, $this->cashierB->id, 200.00);

        $this->assertNotSame($sessionA->id, $sessionB->id, 'S8: distinct branches must produce distinct sessions.');
        $this->assertSame($this->branchA->id, (int) $sessionA->branch_id);
        $this->assertSame($this->branchB->id, (int) $sessionB->branch_id);

        // findOpenSessionForUser must NEVER return a foreign-branch session,
        // even if (theoretically) the same user_id were shared.
        $foundA = $cashService->findOpenSessionForUser($this->branchA->id, $this->cashierA->id);
        $foundB = $cashService->findOpenSessionForUser($this->branchB->id, $this->cashierB->id);
        $this->assertSame($sessionA->id, $foundA?->id);
        $this->assertSame($sessionB->id, $foundB?->id);

        // Cross-lookup : cashier A asking for branch B → null (no foreign session leak).
        $crossLookup = $cashService->findOpenSessionForUser($this->branchB->id, $this->cashierA->id);
        $this->assertNull($crossLookup, 'S8: cross-branch session lookup must return null.');

        // Database-level invariant : sessions are FK-bound to the branch.
        $row = DB::table('cash_drawer_sessions')->where('id', $sessionA->id)->first();
        $this->assertSame($this->branchA->id, (int) $row->branch_id);
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 6 — Availability toggle broadcast scope                    */
    /* (Asserts via broadcast channel naming convention : per-branch       */
    /*  channel `branch.{id}.availability` so a branch B subscriber        */
    /*  cannot receive branch A toggles. We assert at the wiring layer.)   */
    /* ------------------------------------------------------------------ */
    public function test_S6_availability_broadcast_channel_is_branch_scoped(): void
    {
        $channelsFile = base_path('routes/channels.php');
        if (!file_exists($channelsFile)) {
            $this->markTestSkipped('S6: routes/channels.php absent — broadcast wiring not auditable here.');
        }
        $source = file_get_contents($channelsFile);

        // The broadcast channel for availability must be parameterised by branch.
        // Acceptable patterns : "branch.{branch_id}", "branch-{id}.availability", "availability.{branch}", etc.
        $hasBranchScopedChannel = preg_match('/branch[^a-z]?\{[a-z_]+\}|\{branch[a-z_]*\}\.availability|availability\.\{branch[a-z_]*\}/i', $source) === 1;

        $this->assertTrue(
            $hasBranchScopedChannel,
            'S6: availability broadcast channel must be parameterised by branch id (no fleet-wide channel) — see routes/channels.php.'
        );
    }
}
