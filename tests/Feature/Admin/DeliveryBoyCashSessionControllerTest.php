<?php

namespace Tests\Feature\Admin;

use App\Enums\Role as EnumRole;
use App\Http\Controllers\Admin\DeliveryBoyCashSessionController;
use App\Models\Branch;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryBoyCashSession;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [V1.0.2 Sub-6.3 BUILD-1 — 2026-05-18] Feature tests for Admin\DeliveryBoyCashSessionController.
 *
 * Scope :
 *   - All 5 actions (index, show, open, close, reconcile) hit through HTTP
 *   - Authz : `permission:delivery-boys` (mutations) + `permission:delivery-boys_show` (reads)
 *   - BranchScope isolation : staff-A on branch-A cannot see branch-B sessions
 *   - 4xx HttpException → JSON error body shape
 *   - 201 / 200 / 403 / 404 / 422 / 409 status semantics
 *
 * Note routes : BUILD-5 owns the canonical `routes/api.php` registration. This
 * test wires the same controller@action mapping locally in setUp() so the
 * test runs standalone without depending on BUILD-5 landing first. The route
 * URIs match the spec returned to BUILD-5 verbatim (see evidence doc §3).
 */
class DeliveryBoyCashSessionControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User $admin;          // branch_id=0, role Admin
    private User $branchManager;  // branch_id=A
    private User $livreurA;       // branch_id=A
    private User $livreurB;       // branch_id=B
    private User $staffNoPerm;    // branch_id=A, no delivery-boys permission

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));

        // The DELIVERY_BOY role isn't seeded by TestCase::seedSpatieRoles, add it.
        Role::firstOrCreate(
            ['id' => EnumRole::DELIVERY_BOY],
            ['name' => 'Delivery Boy', 'guard_name' => 'sanctum']
        );

        // Add the 5 `delivery-boys*` permissions + grant them to Admin + Branch Manager.
        $deliveryPerms = [
            'delivery-boys',
            'delivery-boys_create',
            'delivery-boys_edit',
            'delivery-boys_delete',
            'delivery-boys_show',
        ];
        foreach ($deliveryPerms as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
        Role::where('name', 'Admin')->where('guard_name', 'sanctum')->first()
            ?->givePermissionTo($deliveryPerms);
        Role::where('name', 'Branch Manager')->where('guard_name', 'sanctum')->first()
            ?->givePermissionTo($deliveryPerms);

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        // Admin — global (branch_id=0).
        $this->admin = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('password'),
            'phone'     => '0102030400',
        ]);
        $this->admin->assignRole('Admin');

        // Branch Manager on branch-A.
        $this->branchManager = User::factory()->create([
            'branch_id' => $this->branchA->id,
            'password'  => Hash::make('password'),
            'phone'     => '0102030401',
        ]);
        $this->branchManager->assignRole('Branch Manager');

        // Livreurs — one per branch.
        $this->livreurA = $this->makeDeliveryBoy($this->branchA->id, '0102030402');
        $this->livreurB = $this->makeDeliveryBoy($this->branchB->id, '0102030403');

        // Staff without delivery-boys permission (POS Operator role only).
        $this->staffNoPerm = User::factory()->create([
            'branch_id' => $this->branchA->id,
            'password'  => Hash::make('password'),
            'phone'     => '0102030404',
        ]);
        $this->staffNoPerm->assignRole('POS Operator');

        // Register routes locally — BUILD-5 owns canonical registration in
        // routes/api.php. Mirror the canonical URI exactly so BUILD-5's drop-in
        // doesn't collide. All routes are sanctum-guarded ; permission middleware
        // is wired in the controller __construct.
        //
        // PRE-PEND : web.php registers a `/{any}` catch-all that would match
        // /api/admin/... and return the SPA HTML. We snapshot the existing
        // route collection, build our new collection (our routes FIRST), then
        // re-append the original routes. The router then matches our specific
        // routes before falling through to the catch-all.
        $router = $this->app['router'];
        $existing = $router->getRoutes();

        $newRoutes = new RouteCollection();
        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api/admin/delivery-boy/cash-sessions')
            ->name('delivery-boy.cash-sessions.')
            ->group(function () use ($newRoutes) {
                $r = Route::get('/', [DeliveryBoyCashSessionController::class, 'index'])->name('index');
                $newRoutes->add($r);
                $r = Route::post('/open', [DeliveryBoyCashSessionController::class, 'open'])->name('open');
                $newRoutes->add($r);
                $r = Route::get('/{session}', [DeliveryBoyCashSessionController::class, 'show'])
                    ->whereNumber('session')->name('show');
                $newRoutes->add($r);
                $r = Route::post('/{session}/close', [DeliveryBoyCashSessionController::class, 'close'])
                    ->whereNumber('session')->name('close');
                $newRoutes->add($r);
                $r = Route::post('/{session}/reconcile', [DeliveryBoyCashSessionController::class, 'reconcile'])
                    ->whereNumber('session')->name('reconcile');
                $newRoutes->add($r);
            });

        // Walk the existing collection (api.php + web.php routes registered
        // earlier in boot) and append after our new routes. Then setRoutes()
        // back. Web.php's catch-all `/{any}` is now AFTER our specific routes,
        // so Laravel matches us first.
        foreach ($existing as $route) {
            $newRoutes->add($route);
        }
        $router->setRoutes($newRoutes);
    }

    private function makeDeliveryBoy(int $branchId, string $phone): User
    {
        $user = User::forceCreate([
            'name'              => 'Livreur '.uniqid(),
            'email'             => 'livreur-'.uniqid().'@sentinel.test',
            'username'          => 'livreur_'.uniqid(),
            'password'          => Hash::make('password'),
            'branch_id'         => $branchId,
            'phone'             => $phone,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        $user->assignRole(EnumRole::DELIVERY_BOY);

        return $user->fresh();
    }

    // ─────────────────────────────────────────────────────────────────
    // ACTION 1 — open()
    // ─────────────────────────────────────────────────────────────────

    public function test_admin_opens_session_for_livreur_returns_201_with_resource(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/delivery-boy/cash-sessions/open', [
                'delivery_boy_id' => $this->livreurA->id,
                'opening_amount'  => 50.00,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', DeliveryBoyCashSession::STATUS_OPEN)
            ->assertJsonPath('data.branch_id', $this->branchA->id)
            ->assertJsonPath('data.delivery_boy_id', $this->livreurA->id)
            ->assertJsonPath('data.opened_by_user_id', $this->admin->id);
        $this->assertEqualsWithDelta(50.0, (float) $response->json('data.opening_amount'), 0.01);

        $this->assertDatabaseHas('delivery_boy_cash_sessions', [
            'branch_id'         => $this->branchA->id,
            'delivery_boy_id'   => $this->livreurA->id,
            'opened_by_user_id' => $this->admin->id,
            'status'            => DeliveryBoyCashSession::STATUS_OPEN,
        ]);
    }

    public function test_open_validation_rejects_non_livreur_user(): void
    {
        // Target the admin himself (not a livreur).
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/delivery-boy/cash-sessions/open', [
                'delivery_boy_id' => $this->admin->id,
                'opening_amount'  => 50.00,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_boy_id']);
    }

    public function test_open_409_when_session_already_open_for_livreur(): void
    {
        app(DeliveryBoyCashSessionService::class)
            ->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/delivery-boy/cash-sessions/open', [
                'delivery_boy_id' => $this->livreurA->id,
                'opening_amount'  => 30.00,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('status', false);
    }

    public function test_open_403_when_branch_manager_tries_cross_branch(): void
    {
        // Branch Manager on branch-A attempts to open a session for livreur on branch-B.
        $response = $this->actingAs($this->branchManager, 'sanctum')
            ->postJson('/api/admin/delivery-boy/cash-sessions/open', [
                'delivery_boy_id' => $this->livreurB->id,
                'opening_amount'  => 50.00,
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('status', false);
    }

    public function test_open_403_when_user_lacks_delivery_boys_permission(): void
    {
        $response = $this->actingAs($this->staffNoPerm, 'sanctum')
            ->postJson('/api/admin/delivery-boy/cash-sessions/open', [
                'delivery_boy_id' => $this->livreurA->id,
                'opening_amount'  => 50.00,
            ]);

        $response->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────
    // ACTION 2 — close()
    // ─────────────────────────────────────────────────────────────────

    public function test_admin_closes_session_returns_200_with_status_closed(): void
    {
        $session = app(DeliveryBoyCashSessionService::class)
            ->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/delivery-boy/cash-sessions/{$session->id}/close", [
                'closing_amount' => 80.00,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', DeliveryBoyCashSession::STATUS_CLOSED);
        $this->assertEqualsWithDelta(80.0, (float) $response->json('data.closing_amount'), 0.01);

        $this->assertSame(
            DeliveryBoyCashSession::STATUS_CLOSED,
            $session->fresh()->status,
        );
    }

    public function test_close_validation_rejects_negative_amount(): void
    {
        $session = app(DeliveryBoyCashSessionService::class)
            ->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/delivery-boy/cash-sessions/{$session->id}/close", [
                'closing_amount' => -10.00,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['closing_amount']);
    }

    public function test_close_404_cross_branch_via_route_model_binding(): void
    {
        // Open a session on branch-B with livreur-B (caller=admin, allowed).
        $sessionB = app(DeliveryBoyCashSessionService::class)
            ->openSession($this->branchB->id, $this->livreurB->id, 50.00, $this->admin->id);

        // Branch Manager on branch-A attempts to close branch-B session.
        // BranchScope hides branch-B rows → route model binding → 404.
        $response = $this->actingAs($this->branchManager, 'sanctum')
            ->postJson("/api/admin/delivery-boy/cash-sessions/{$sessionB->id}/close", [
                'closing_amount' => 50.00,
            ]);

        $response->assertStatus(404);

        $this->assertSame(
            DeliveryBoyCashSession::STATUS_OPEN,
            $sessionB->fresh()->status,
            'Branch-B session must remain OPEN — branch-A manager has no visibility.',
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // ACTION 3 — reconcile()
    // ─────────────────────────────────────────────────────────────────

    public function test_reconcile_returns_200_with_expected_and_variance(): void
    {
        $service = app(DeliveryBoyCashSessionService::class);
        $session = $service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);
        $service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            10.00,
            DeliveryBoyCashMovement::DIRECTION_IN,
        );
        $service->closeSession($session->id, 60.00);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/delivery-boy/cash-sessions/{$session->id}/reconcile", [
                'variance_reason' => null,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', DeliveryBoyCashSession::STATUS_RECONCILED);
        $this->assertEqualsWithDelta(60.0, (float) $response->json('data.expected'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $response->json('data.variance'), 0.01);
    }

    public function test_reconcile_422_when_session_still_open(): void
    {
        $session = app(DeliveryBoyCashSessionService::class)
            ->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/delivery-boy/cash-sessions/{$session->id}/reconcile", []);

        $response->assertStatus(422)
            ->assertJsonPath('status', false);
    }

    // ─────────────────────────────────────────────────────────────────
    // ACTION 4 — show()
    // ─────────────────────────────────────────────────────────────────

    public function test_show_returns_session_with_movements_eager_loaded(): void
    {
        $service = app(DeliveryBoyCashSessionService::class);
        $session = $service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);
        $service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            15.50,
            DeliveryBoyCashMovement::DIRECTION_IN,
            notes: 'order #1',
        );
        $service->recordMovement(
            $session->id,
            DeliveryBoyCashMovement::TYPE_CHANGE_GIVEN,
            2.50,
            DeliveryBoyCashMovement::DIRECTION_OUT,
        );

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/delivery-boy/cash-sessions/{$session->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.movements.0.type', DeliveryBoyCashMovement::TYPE_ORDER_COLLECT)
            ->assertJsonPath('data.movements.1.type', DeliveryBoyCashMovement::TYPE_CHANGE_GIVEN)
            ->assertJsonPath('data.movements.1.direction', DeliveryBoyCashMovement::DIRECTION_OUT);
        $this->assertEqualsWithDelta(15.50, (float) $response->json('data.movements.0.amount'), 0.01);

        $payload = $response->json('data.movements');
        $this->assertIsArray($payload);
        $this->assertCount(2, $payload);
    }

    public function test_show_404_cross_branch_via_branch_scope(): void
    {
        $sessionB = app(DeliveryBoyCashSessionService::class)
            ->openSession($this->branchB->id, $this->livreurB->id, 50.00, $this->admin->id);

        $response = $this->actingAs($this->branchManager, 'sanctum')
            ->getJson("/api/admin/delivery-boy/cash-sessions/{$sessionB->id}");

        $response->assertStatus(404);
    }

    public function test_show_403_when_user_lacks_show_permission(): void
    {
        $session = app(DeliveryBoyCashSessionService::class)
            ->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);

        $response = $this->actingAs($this->staffNoPerm, 'sanctum')
            ->getJson("/api/admin/delivery-boy/cash-sessions/{$session->id}");

        $response->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────
    // ACTION 5 — index()
    // ─────────────────────────────────────────────────────────────────

    public function test_index_returns_list_with_pagination(): void
    {
        $service = app(DeliveryBoyCashSessionService::class);
        $s1 = $service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);
        $service->closeSession($s1->id, 50.00);
        $service->openSession($this->branchB->id, $this->livreurB->id, 75.00, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/delivery-boy/cash-sessions');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_index_branch_scope_isolates_branches_for_non_global_user(): void
    {
        $service = app(DeliveryBoyCashSessionService::class);
        $service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);
        $service->openSession($this->branchB->id, $this->livreurB->id, 75.00, $this->admin->id);

        // Branch Manager on branch-A → only branch-A sessions visible.
        $response = $this->actingAs($this->branchManager, 'sanctum')
            ->getJson('/api/admin/delivery-boy/cash-sessions');

        $response->assertStatus(200)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.branch_id', $this->branchA->id);
    }

    public function test_index_filter_by_delivery_boy_id(): void
    {
        $service = app(DeliveryBoyCashSessionService::class);
        $service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);
        $service->openSession($this->branchB->id, $this->livreurB->id, 75.00, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/delivery-boy/cash-sessions?delivery_boy_id='.$this->livreurB->id);

        $response->assertStatus(200)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.delivery_boy_id', $this->livreurB->id);
    }

    public function test_index_filter_by_status(): void
    {
        $service = app(DeliveryBoyCashSessionService::class);
        $sA = $service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);
        $service->closeSession($sA->id, 50.00);
        $service->openSession($this->branchB->id, $this->livreurB->id, 75.00, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/delivery-boy/cash-sessions?status=closed');

        $response->assertStatus(200)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.status', DeliveryBoyCashSession::STATUS_CLOSED);
    }

    public function test_index_403_when_user_lacks_show_permission(): void
    {
        $response = $this->actingAs($this->staffNoPerm, 'sanctum')
            ->getJson('/api/admin/delivery-boy/cash-sessions');

        $response->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────
    // [W-REM T-R2.3 Q-1 2026-06-12] Finding D-B3-01 : la page Caisses
    // Livreur affichait les IDs bruts (LIVREUR «10», FILIALE «1») car la
    // resource ne shippait que delivery_boy_id/branch_id. L'UI doit
    // pouvoir rendre les NOMS sans requête supplémentaire.
    // ─────────────────────────────────────────────────────────────────

    public function test_index_ships_delivery_boy_name_and_branch_name(): void
    {
        $service = app(DeliveryBoyCashSessionService::class);
        $service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/delivery-boy/cash-sessions');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.delivery_boy_name', $this->livreurA->name)
            ->assertJsonPath('data.0.branch_name', $this->branchA->name);
    }

    public function test_show_ships_delivery_boy_name_and_branch_name(): void
    {
        $service = app(DeliveryBoyCashSessionService::class);
        $session = $service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->admin->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/delivery-boy/cash-sessions/{$session->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.delivery_boy_name', $this->livreurA->name)
            ->assertJsonPath('data.branch_name', $this->branchA->name);
    }
}
