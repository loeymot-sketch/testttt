<?php

namespace Tests\Feature\Foundation;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use App\Services\FirebaseService;
use App\Services\PushNotificationService;
use App\Http\Requests\PushNotificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * [Foundation Audit F-8-RED-001 / 2026-05-18]
 *
 * Push notification fan-out must respect tenant isolation.
 *
 * Before the fix at PushNotificationService.php:71-90, role_id=0+user_id=0
 * broadcasts and role-scoped fan-outs selected ALL users across ALL branches,
 * ignoring the branch_id on the push_notifications row. A branch manager's
 * promo push reached staff of other restaurants.
 *
 * This sentinel locks the post-fix contract :
 *   - push.branch_id == 0  ⇒  fan-out unchanged (admin global broadcast)
 *   - push.branch_id == N  ⇒  fan-out filtered to branch N only
 *   - Single-user path     ⇒  unchanged (already isolated)
 */
class PushNotificationTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var Mockery\MockInterface */
    private $capturedTokens;

    protected function setUp(): void
    {
        parent::setUp();

        // Capture tokens passed to FirebaseService->sendNotification without
        // actually calling FCM. The PushNotificationService now resolves the
        // service via app() so this binding is honoured.
        $this->capturedTokens = [];
        $mock = Mockery::mock(FirebaseService::class);
        $mock->shouldReceive('sendNotification')
            ->andReturnUsing(function ($pushNotification, $tokens, $type) {
                $this->capturedTokens[] = $tokens;
                return null;
            });
        $this->app->instance(FirebaseService::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeUser(int $branchId, string $webToken, string $mobileToken): User
    {
        return User::factory()->create([
            'branch_id'    => $branchId,
            'web_token'    => $webToken,
            'device_token' => $mobileToken,
            'status'       => Status::ACTIVE,
        ]);
    }

    private function callStore(int $branchId, int $roleId = 0, int $userId = 0): void
    {
        $request = Mockery::mock(PushNotificationRequest::class);
        $request->title       = 'sentinel test';
        $request->description = 'sentinel description';
        $request->role_id     = $roleId;
        $request->user_id     = $userId;
        $request->branch_id   = $branchId;
        $request->shouldReceive('hasFile')->andReturn(false);

        $service = new PushNotificationService();
        $service->store($request);
    }

    public function test_branch_scoped_broadcast_filters_users_by_branch_id(): void
    {
        // Two branches with users carrying tokens.
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $this->makeUser($branchA->id, 'web-A1', 'mob-A1');
        $this->makeUser($branchA->id, 'web-A2', 'mob-A2');
        $this->makeUser($branchB->id, 'web-B1', 'mob-B1');
        $this->makeUser($branchB->id, 'web-B2', 'mob-B2');

        $this->callStore(branchId: $branchA->id);

        $tokens = array_merge(...$this->capturedTokens);
        sort($tokens);

        $this->assertSame(
            ['mob-A1', 'mob-A2', 'web-A1', 'web-A2'],
            $tokens,
            'Branch-scoped push must NOT leak tokens to users of other branches.'
        );
    }

    public function test_admin_branch_zero_broadcast_keeps_global_fanout(): void
    {
        // Admin push (branch_id=0) is unchanged: reaches everyone.
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $this->makeUser($branchA->id, 'web-A', 'mob-A');
        $this->makeUser($branchB->id, 'web-B', 'mob-B');

        $this->callStore(branchId: 0);

        $tokens = array_merge(...$this->capturedTokens);
        sort($tokens);

        $this->assertSame(
            ['mob-A', 'mob-B', 'web-A', 'web-B'],
            $tokens,
            'Admin global broadcast (branch_id=0) must keep cross-branch fan-out.'
        );
    }

    public function test_single_user_path_is_branch_isolated(): void
    {
        // [abuse-heal 2026-06-20 W6r2 PUSH-SINGLEUSER-XBRANCH-01] This sentinel was FALSE-GREEN: it
        // only ever targeted a user in the SAME branch as the push, so it proved nothing about
        // cross-branch isolation (just "WHERE id=X returns X"). It now exercises the real attack:
        // a branch-A push targeting a user_id that lives in branch B must NOT reach that victim.
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        // (1) Functional control — a same-branch single-user push DOES reach the in-branch target.
        $target = $this->makeUser($branchA->id, 'web-target', 'mob-target');
        $this->callStore(branchId: $branchA->id, userId: $target->id);
        $tokens = array_merge([], ...$this->capturedTokens);
        sort($tokens);
        $this->assertSame(
            ['mob-target', 'web-target'],
            $tokens,
            'Single-user push must reach the in-branch target.'
        );

        // (2) Cross-branch isolation — a branch-A push targeting a branch-B victim must capture NO
        // branch-B tokens. Pre-heal the single-user query had no branch filter and leaked here.
        $this->capturedTokens = [];
        $victimB = $this->makeUser($branchB->id, 'web-victimB', 'mob-victimB');
        $this->callStore(branchId: $branchA->id, userId: $victimB->id);
        $crossTokens = array_merge([], ...$this->capturedTokens);

        $this->assertNotContains('web-victimB', $crossTokens, 'Branch-A push leaked to a branch-B victim (web).');
        $this->assertNotContains('mob-victimB', $crossTokens, 'Branch-A push leaked to a branch-B victim (mobile).');
    }

    /**
     * [abuse-heal 2026-06-20 W6r5 PUSH-CREATE-BRANCHID-FORGE-01] HTTP create-time clamp. A branch-scoped
     * Branch Manager who forges branch_id=0 (global) or branch_id=<other branch> must have it CLAMPED to
     * their own branch — they cannot global-broadcast nor cross-target. Pre-heal both were stored verbatim.
     */
    public function test_branch_manager_cannot_forge_branch_id_on_create(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $ownBranch = Branch::factory()->create();
        $foreignBranch = Branch::factory()->create();

        $manager = User::factory()->create(['branch_id' => $ownBranch->id, 'status' => Status::ACTIVE]);
        $manager->assignRole('Branch Manager');
        $manager->givePermissionTo(
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'push-notifications_create', 'guard_name' => 'sanctum'])
        );

        // (1) Forge global broadcast (branch_id=0) -> clamped to own branch.
        $this->actingAs($manager, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/api/admin/push-notification', [
                'title' => 'forge-global', 'description' => 'x', 'role_id' => 0, 'user_id' => 0, 'branch_id' => 0,
            ])->assertSuccessful();
        $this->assertSame(
            $ownBranch->id,
            (int) \App\Models\PushNotification::latest('id')->first()->branch_id,
            'Branch Manager forging branch_id=0 must be clamped to own branch (no global escalation).'
        );

        // (2) Forge cross-branch (branch_id=foreign) -> clamped to own branch.
        $this->actingAs($manager, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/api/admin/push-notification', [
                'title' => 'forge-cross', 'description' => 'x', 'role_id' => 0, 'user_id' => 0, 'branch_id' => $foreignBranch->id,
            ])->assertSuccessful();
        $this->assertSame(
            $ownBranch->id,
            (int) \App\Models\PushNotification::latest('id')->first()->branch_id,
            'Branch Manager forging a foreign branch_id must be clamped to own branch (no cross-branch injection).'
        );
    }
}
