<?php

namespace Tests\Feature\Sentinels;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [Admin S-1 P0 IDOR — heal/cms-pr1-quickwins-2026-05-18]
 *
 * Pre-heal vulnerability (audit AD-2-security):
 *   Route GET /api/admin/my-order/show/{user}/{order} has ZERO permission
 *   middleware. The only authz check is in OrderService::orderDetails
 *   which compares `$order->user_id == $user->id` — i.e. URL params to
 *   each other, NOT to auth()->id(). Any authenticated user who guesses
 *   a valid (user_id, order_id) pair (e.g. by enumeration) can read the
 *   order, even with zero admin-side permissions.
 *
 * Heal (scope-minimal):
 *   Apply `permission:customers_show|waiters_show|delivery-boys_show|
 *   chefs_show|administrators_show|employees_show` alternation gate on
 *   the route declaration in routes/api.php (line 574). Aligns with the
 *   6 consumer SPA flows that already gate their own *_show endpoints
 *   via this same permission. Spatie middleware OR-semantics: any one
 *   permission grants access.
 *
 * V1.0.2 follow-up (NOT in this heal):
 *   OrderService::orderDetails still does URL-param comparison rather
 *   than auth()->id() check. True IDOR closure requires touching the
 *   service layer (OrderService is on the DIRTY list — owner gate).
 *   This sentinel narrows the attack surface from any-authenticated
 *   to users-with-one-of-6-perms.
 *
 * Sentinel scenarios (6) :
 *   1. Anonymous request                      → 401 (sanctum fires first)
 *   2. Customer-permission user, owns order   → 200 OK
 *   3. Waiter-permission user, owns order     → 200 OK
 *   4. DeliveryBoy-permission user, owns order→ 200 OK
 *   5. User with ZERO of the 6 perms          → 403 (the IDOR fix)
 *   6. Cross-branch staff (different branch)  → 404 (BranchScope defense)
 */
class MyOrderDetailsAuthzSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Seed the 6 *_show permissions that gate the alternation middleware.
        // Not in TestCase::seedSpatieRoles() — restrict to this sentinel.
        foreach ([
            'customers_show',
            'waiters_show',
            'delivery-boys_show',
            'chefs_show',
            'administrators_show',
            'employees_show',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }

        $this->branch = Branch::factory()->create();
    }

    /**
     * Build a fixture (orderOwner + order). The {user} URL param is the
     * order owner; route authz is about who can REQUEST the endpoint,
     * not whose order it is.
     */
    private function makeOrderInBranch(int $branchId): array
    {
        $orderOwner = User::factory()->create(['branch_id' => $branchId]);
        $order = Order::factory()->create([
            'user_id'   => $orderOwner->id,
            'branch_id' => $branchId,
        ]);

        return [$orderOwner, $order];
    }

    private function makeRequester(int $branchId, ?string $permission): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);
        if ($permission !== null) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function showUrl(User $orderOwner, Order $order): string
    {
        return "/api/admin/my-order/show/{$orderOwner->id}/{$order->id}";
    }

    public function test_anonymous_request_is_unauthenticated_401(): void
    {
        [$orderOwner, $order] = $this->makeOrderInBranch($this->branch->id);

        $response = $this->getJson($this->showUrl($orderOwner, $order));

        $this->assertSame(
            401,
            $response->getStatusCode(),
            'Anonymous request to my-order/show MUST be rejected by auth:sanctum (401).'
        );
    }

    public function test_customer_show_permission_user_can_access(): void
    {
        [$orderOwner, $order] = $this->makeOrderInBranch($this->branch->id);
        $requester = $this->makeRequester($this->branch->id, 'customers_show');

        $response = $this->actingAs($requester, 'sanctum')
            ->getJson($this->showUrl($orderOwner, $order));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            "User with `customers_show` MUST access my-order/show. Body: ".$response->getContent()
        );
    }

    public function test_waiter_show_permission_user_can_access(): void
    {
        [$orderOwner, $order] = $this->makeOrderInBranch($this->branch->id);
        $requester = $this->makeRequester($this->branch->id, 'waiters_show');

        $response = $this->actingAs($requester, 'sanctum')
            ->getJson($this->showUrl($orderOwner, $order));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            "User with `waiters_show` MUST access my-order/show. Body: ".$response->getContent()
        );
    }

    public function test_delivery_boy_show_permission_user_can_access(): void
    {
        [$orderOwner, $order] = $this->makeOrderInBranch($this->branch->id);
        $requester = $this->makeRequester($this->branch->id, 'delivery-boys_show');

        $response = $this->actingAs($requester, 'sanctum')
            ->getJson($this->showUrl($orderOwner, $order));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            "User with `delivery-boys_show` MUST access my-order/show. Body: ".$response->getContent()
        );
    }

    public function test_user_without_any_show_permission_is_forbidden_403(): void
    {
        [$orderOwner, $order] = $this->makeOrderInBranch($this->branch->id);
        // Requester is authenticated but has NONE of the 6 *_show permissions.
        $requester = $this->makeRequester($this->branch->id, null);

        $response = $this->actingAs($requester, 'sanctum')
            ->getJson($this->showUrl($orderOwner, $order));

        $this->assertSame(
            403,
            $response->getStatusCode(),
            "[Admin S-1 IDOR] User WITHOUT any *_show permission MUST be denied 403. ".
            "Pre-heal: this returned 200 (the IDOR). Body: ".$response->getContent()
        );
    }

    public function test_cross_branch_requester_cannot_see_order_404(): void
    {
        // Order belongs to branch A.
        [$orderOwner, $order] = $this->makeOrderInBranch($this->branch->id);

        // Requester is on branch B and HAS customers_show — but BranchScope
        // hides the order from route-model-binding → ModelNotFoundException
        // → Handler::render() maps to 404 (ORDER_NOT_FOUND).
        $branchB = Branch::factory()->create();
        $requester = $this->makeRequester($branchB->id, 'customers_show');

        $response = $this->actingAs($requester, 'sanctum')
            ->getJson($this->showUrl($orderOwner, $order));

        $this->assertSame(
            404,
            $response->getStatusCode(),
            "[BranchScope defense] Cross-branch requester MUST get 404 ".
            "(route-model-binding scoped out). Body: ".$response->getContent()
        );
    }
}
