<?php

namespace Tests\Feature\Sentinels;

use App\Models\Analytic;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [WJ-1 / WI-4-RED-01 P0 SECURITY — heal/cms-pr1-quickwins-2026-05-18]
 *
 * Pre-heal vulnerability:
 *   Three admin mutation route groups in routes/api.php were reachable by any
 *   authenticated user (including Customer Sanctum tokens minted with
 *   abilities=['*']):
 *
 *     - POST   /api/admin/default-access                (storeOrUpdate)
 *     - POST   /api/admin/setting/menu-template         (store)
 *     - PUT    /api/admin/setting/menu-template/{id}    (update)
 *     - DELETE /api/admin/setting/menu-template/{id}    (destroy)
 *     - POST   /api/admin/setting/analytic-section/{analytic}
 *     - PUT    /api/admin/setting/analytic-section/{analytic}/{section}
 *     - DELETE /api/admin/setting/analytic-section/{analytic}/{section}
 *
 *   Route middleware stack admitted: apiKey → auth:sanctum →
 *   EnsureUserStatusActive → throttle:admin-mutation — no permission gate.
 *   FormRequest `authorize()` returns true (MenuTemplateRequest:17,
 *   AnalyticSectionRequest:14). Controllers had no __construct middleware.
 *
 * Heal (scope-minimal, per-verb):
 *   Add `$this->middleware(['permission:settings'])->only(...)` in each of
 *   the 3 controllers' __construct (mirrors ~20 existing examples:
 *   CurrencyController:21, SliderController:27, ItemAttributeController:22).
 *   - MenuTemplateController::__construct        → only('store','update','destroy')
 *   - DefaultAccessController::__construct       → only('storeOrUpdate')
 *   - AnalyticSectionController::__construct     → only('store','update','destroy')
 *
 *   PIVOT from prefix-level gate to per-verb: PosMenuRuntimeAccessTest
 *   exercises GET /api/admin/default-access from a POS-Operator without
 *   `settings`. Prefix-level gate would regress that 200 → 403.
 *
 * Sentinel scenarios (7) :
 *   1. Customer-token POST menu-template          → 403 (the fix)
 *   2. settings-permission POST menu-template     → 200/201 (perm grants access)
 *   3. Customer-token POST default-access         → 403 (the fix)
 *   4. settings-permission POST default-access    → 200    (perm grants access)
 *   5. Customer-token POST analytic-section       → 403 (the fix)
 *   6. settings-permission POST analytic-section  → 200/201 (perm grants access)
 *   7. POS-Operator GET default-access            → 200 (regression guard;
 *                                                   per-verb pivot rationale)
 *
 * Each Customer-token POST uses a VALID payload so the assertion targets
 * the authorization gate, not FormRequest validation. Pre-fix the Customer
 * gets 200/201 (proving the bug); post-fix the `permission:` middleware
 * short-circuits before the FormRequest runs and the response is 403.
 */
class RouteCoverage_AdminPermissionGateSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();      // ensures `settings` Permission exists + Admin role wired
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
    }

    /**
     * A "Customer" user — authenticated but with NO `settings` permission.
     * Mirrors a real customer logging in to the customer-facing app and the
     * resulting Sanctum token being replayed against admin endpoints.
     */
    private function makeCustomer(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        // Explicitly attach the Customer role (which has NO `settings` perm)
        // to make the test intent unambiguous even if a future drift adds
        // permissions to bare User accounts.
        if (\Spatie\Permission\Models\Role::where(['name' => 'Customer', 'guard_name' => 'sanctum'])->exists()) {
            $user->assignRole('Customer');
        }
        return $user;
    }

    /**
     * A staff user with the `settings` permission directly granted —
     * the minimum credential the heal expects to admit a request.
     */
    private function makeSettingsHolder(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->givePermissionTo('settings');
        return $user;
    }

    /**
     * A POS Operator — exercises the regression guard. POS Operator role
     * is wired in TestCase::seedSpatieRoles() WITHOUT `settings`, but
     * production code requires this user to be able to GET default-access
     * (cf. PosMenuRuntimeAccessTest::test_pos_operator_bootstrap_*).
     */
    private function makePosOperator(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('POS Operator');
        return $user;
    }

    // ------------------------------------------------------------------ //
    // 1-2: menu-template POST
    // ------------------------------------------------------------------ //

    public function test_customer_token_cannot_create_menu_template(): void
    {
        $customer = $this->makeCustomer();

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/admin/setting/menu-template', [
                'name' => 'sentinel-attack-' . uniqid(),
            ]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            "[WJ-1 P0] Customer-token POST menu-template MUST be denied 403. "
            . "Pre-heal this returned 201 (the privilege escalation). "
            . "Body: " . $response->getContent()
        );
    }

    public function test_settings_permission_holder_can_create_menu_template(): void
    {
        $admin = $this->makeSettingsHolder();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/setting/menu-template', [
                'name' => 'sentinel-legit-' . uniqid(),
            ]);

        $this->assertContains(
            $response->getStatusCode(),
            [200, 201],
            "User with `permission:settings` MUST be able to create a menu-template. "
            . "Status: " . $response->getStatusCode() . " Body: " . $response->getContent()
        );
    }

    // ------------------------------------------------------------------ //
    // 3-4: default-access POST (storeOrUpdate)
    // ------------------------------------------------------------------ //

    public function test_customer_token_cannot_write_default_access(): void
    {
        $customer = $this->makeCustomer();

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/admin/default-access', [
                'branch_id' => $this->branch->id,
            ]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            "[WJ-1 P0] Customer-token POST default-access MUST be denied 403. "
            . "Pre-heal this returned 200 (the privilege escalation). "
            . "Body: " . $response->getContent()
        );
    }

    public function test_settings_permission_holder_can_write_default_access(): void
    {
        $admin = $this->makeSettingsHolder();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/default-access', [
                'branch_id' => $this->branch->id,
            ]);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            "User with `permission:settings` MUST be able to write default-access. "
            . "Body: " . $response->getContent()
        );
    }

    // ------------------------------------------------------------------ //
    // 5-6: analytic-section POST
    // ------------------------------------------------------------------ //

    public function test_customer_token_cannot_create_analytic_section(): void
    {
        $customer = $this->makeCustomer();
        $analytic = Analytic::create(['name' => 'sentinel-analytic-c-' . uniqid(), 'status' => 1]);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/admin/setting/analytic-section/' . $analytic->id, [
                'name'    => 'sentinel-attack-' . uniqid(),
                'section' => 1,
                'data'    => 'attack-payload',
            ]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            "[WJ-1 P0] Customer-token POST analytic-section MUST be denied 403. "
            . "Pre-heal this returned 201 (the privilege escalation). "
            . "Body: " . $response->getContent()
        );
    }

    public function test_settings_permission_holder_can_create_analytic_section(): void
    {
        $admin = $this->makeSettingsHolder();
        $analytic = Analytic::create(['name' => 'sentinel-analytic-s-' . uniqid(), 'status' => 1]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/setting/analytic-section/' . $analytic->id, [
                'name'    => 'sentinel-legit-' . uniqid(),
                'section' => 1,
                'data'    => 'legit-payload',
            ]);

        $this->assertContains(
            $response->getStatusCode(),
            [200, 201],
            "User with `permission:settings` MUST be able to create an analytic-section. "
            . "Status: " . $response->getStatusCode() . " Body: " . $response->getContent()
        );
    }

    // ------------------------------------------------------------------ //
    // 7: regression guard — POS-Operator GET default-access must stay OK
    //    (documents the per-verb pivot rationale; ensures the heal does
    //    not regress PosMenuRuntimeAccessTest's bootstrap expectation).
    // ------------------------------------------------------------------ //

    public function test_pos_operator_can_still_read_default_access_after_heal(): void
    {
        $pos = $this->makePosOperator();

        $response = $this->actingAs($pos, 'sanctum')
            ->getJson('/api/admin/default-access');

        $this->assertSame(
            200,
            $response->getStatusCode(),
            "[Regression guard] POS Operator MUST retain GET default-access after the "
            . "per-verb permission:settings gate is installed on storeOrUpdate. If this "
            . "fails, the heal pivot to per-verb has regressed. "
            . "Body: " . $response->getContent()
        );
    }
}
