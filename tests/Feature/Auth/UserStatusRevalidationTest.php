<?php

namespace Tests\Feature\Auth;

use App\Enums\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Sprint H1 Z6-06 2026-05-17] Sanctum personal access tokens are
 * independent of `users.status`. Pre-patch, an admin disabling a user
 * (status -> INACTIVE) had no effect on already-issued tokens — they
 * survived the full 480min TTL (config sanctum.expiration), giving up
 * to 8h propagation delay for a security-critical revocation.
 *
 * Owner Gate G4 chose Option A (every-request middleware) over
 * periodic-cache or TTL-reduction. `EnsureUserStatusActive` runs after
 * `auth:sanctum` resolves the user and rejects requests + revokes the
 * presented token when `users.status !== Status::ACTIVE`.
 *
 * CLAUDE.md §9 (multi-tenant + auth invariants).
 *
 * Test endpoint: `/api/profile` (routes/api.php:240) — minimal Sanctum-
 * protected route requiring [installed, apiKey, auth:sanctum,
 * localization]. We mirror tests/Feature/Auth/GuestSignupAbilityScopeTest
 * setUp() for the api_key + installed sentinel pattern.
 */
class UserStatusRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        config(['app.api_key' => self::API_KEY]);

        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);
    }

    /** @test */
    public function test_disabled_user_token_rejected_on_next_request(): void
    {
        // Reserve id=1 — User model has a boot.updating hook that
        // force-resets id=1's status to ACTIVE (root admin sentinel,
        // app/Models/User.php:125-129). Use a non-root row for the
        // status-flip scenario.
        User::factory()->create(['status' => Status::ACTIVE]);

        // Active user mints a token via Sanctum.
        $user = User::factory()->create([
            'status' => Status::ACTIVE,
        ]);
        $token = $user->createToken('auth_token', ['*'])->plainTextToken;

        // First request — user active — must NOT be 401 (it may be 200 or
        // any non-401; we only assert that auth itself succeeded).
        $r1 = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/profile');
        $this->assertNotSame(
            401,
            $r1->status(),
            'Active user should not receive 401 on initial /api/profile request.'
        );

        // Admin disables the user (simulated admin mutation).
        $user->update(['status' => Status::INACTIVE]);

        // Sanity-check DB state — guards against a User::updating boot
        // hook silently rewriting status (see app/Models/User.php line
        // 125 for the id=1 root-admin sentinel that would foil this).
        $this->assertSame(
            (int) Status::INACTIVE,
            (int) \DB::table('users')->where('id', $user->id)->value('status'),
            'User status must persist to INACTIVE — if this fails, the user is hitting the id=1 root-admin sentinel.'
        );

        // Second request with the SAME token must now 401.
        $r2 = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/profile');
        $r2->assertStatus(401);

        // The presented token must be revoked, not merely rejected, to
        // prevent replay if the user is re-enabled.
        $this->assertSame(
            0,
            $user->fresh()->tokens()->count(),
            'Disabled-user token must be revoked (deleted), not just blocked.'
        );
    }

    /** @test */
    public function test_anonymous_route_not_blocked_by_middleware(): void
    {
        // Public api route (no auth:sanctum) — /api/health/live is the
        // minimal public probe (HealthController::live, routes/api.php:139).
        // Middleware must skip cleanly when no user is resolved.
        $r = $this->getJson('/api/health/live');

        // 401 here would indicate the middleware ran for an anonymous
        // request and rejected it — that's the regression we guard
        // against. Any 2xx is the expected success signal for /health/live.
        $this->assertNotSame(
            401,
            $r->status(),
            'Anonymous /api/health/live should not be blocked by EnsureUserStatusActive.'
        );
    }

    /** @test */
    public function test_inactive_user_blocked_even_with_wildcard_ability(): void
    {
        // Reserve id=1 — see test 1 rationale (root admin status sentinel).
        User::factory()->create(['status' => Status::ACTIVE]);

        // Defense-in-depth: even a wildcard-ability token (legacy guest
        // signup path pre-Z6-02) must be revoked when status flips. The
        // ability check is independent of status check.
        $user = User::factory()->create([
            'status' => Status::INACTIVE,
        ]);
        $token = $user->createToken('auth_token', ['*'])->plainTextToken;

        $r = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/profile');
        $r->assertStatus(401);

        $this->assertSame(
            0,
            $user->fresh()->tokens()->count(),
            'Inactive user token must be revoked on first request even with wildcard ability.'
        );
    }

    /**
     * [T-9.4.1 — V1.0.2 R-3 2026-05-18]
     *
     * AD09 case 4 — flipping one user's status MUST NOT affect a second,
     * independently-authenticated user with an independent token. This
     * isolates the middleware to per-user side-effects (no cross-user
     * token-revocation leak via shared cache or wrongly-scoped delete).
     *
     * @test
     */
    public function test_deactivating_one_user_does_not_affect_another_active_user(): void
    {
        // Reserve id=1 — root admin status sentinel.
        User::factory()->create(['status' => Status::ACTIVE]);

        $userA = User::factory()->create(['status' => Status::ACTIVE]);
        $userB = User::factory()->create(['status' => Status::ACTIVE]);

        $tokenA = $userA->createToken('auth_token_A', ['*'])->plainTextToken;
        $tokenB = $userB->createToken('auth_token_B', ['*'])->plainTextToken;

        // Sanity: both users have one token initially.
        $this->assertSame(1, $userA->fresh()->tokens()->count());
        $this->assertSame(1, $userB->fresh()->tokens()->count());

        // Flip ONLY userA to INACTIVE.
        $userA->update(['status' => Status::INACTIVE]);

        // userA's token must be rejected on next request.
        $rA = $this->withHeader('Authorization', "Bearer $tokenA")
            ->getJson('/api/profile');
        $rA->assertStatus(401);

        // userA's token must be revoked.
        $this->assertSame(
            0,
            $userA->fresh()->tokens()->count(),
            'Deactivated user A token must be revoked.'
        );

        // Sanity: userB row in DB is ACTIVE (=5). If this assertion fails,
        // the User::boot.updating root-admin sentinel or branch_id default
        // may have mutated B's status.
        $this->assertSame(
            (int) Status::ACTIVE,
            (int) \DB::table('users')->where('id', $userB->id)->value('status'),
            'User B status must remain ACTIVE in DB before token-B request.'
        );

        // Flush auth state between requests — Laravel's TestCase resolves
        // and caches the user via the auth guard during the first request
        // (rA), so $request->user() in the second request would still
        // return the cached userA regardless of the new Authorization
        // header. Calling forgetGuards() forces a fresh guard resolution
        // for tokenB. This mirrors production behavior, where each HTTP
        // request hits a separate guard instance.
        $this->app['auth']->forgetGuards();

        // userB's token MUST remain valid (status still ACTIVE).
        $rB = $this->withHeader('Authorization', "Bearer $tokenB")
            ->getJson('/api/profile');
        $bodyB = $rB->getContent();
        $this->assertNotSame(
            401,
            $rB->status(),
            'Active user B token must NOT be affected by user A deactivation. Body: '.$bodyB
        );

        // userB's token row must remain.
        $this->assertSame(
            1,
            $userB->fresh()->tokens()->count(),
            'Active user B token MUST remain in personal_access_tokens.'
        );
    }
}
