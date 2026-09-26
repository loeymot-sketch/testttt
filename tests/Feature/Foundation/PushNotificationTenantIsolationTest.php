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

                // [ONB-09 2026-08-28] Le double rendait `null`, ce que la signature
                // `void` d'alors autorisait. `sendNotification()` rend desormais un
                // COMPTE RENDU : sans lui, l'ecran ne pouvait pas savoir si la
                // notification etait partie, et annoncait un succes inconditionnel.
                // Le double doit honorer le nouveau contrat — ce banc-ci porte sur
                // l'isolation par branche, pas sur le compte rendu, donc on rend une
                // reussite complete pour les jetons recus.
                return [
                    'destinataires' => count($tokens),
                    'envoyes'       => count($tokens),
                    'echecs'        => 0,
                    'erreur'        => null,
                ];
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
        // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] store() now calls
        // $request->user() to clamp branch_id server-side for non-`settings`
        // callers (see PushNotificationBranchIdSpoofTest for that behavior).
        // null here preserves this test's original intent: it exercises the
        // FAN-OUT logic given an already-determined branch_id, not the
        // input-side clamp — with no caller, effectiveBranchId() passes the
        // requested value through unchanged.
        $request->shouldReceive('user')->andReturn(null);

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

    public function test_single_user_path_remains_isolated(): void
    {
        // Single-user fan-out (user_id != 0) was already safe — lock the contract.
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $target = $this->makeUser($branchA->id, 'web-target', 'mob-target');
        $this->makeUser($branchB->id, 'web-other', 'mob-other');

        $this->callStore(branchId: $branchA->id, userId: $target->id);

        $tokens = array_merge(...$this->capturedTokens);
        sort($tokens);

        $this->assertSame(
            ['mob-target', 'web-target'],
            $tokens,
            'Single-user push must NOT pick up other branches.'
        );
    }
}
