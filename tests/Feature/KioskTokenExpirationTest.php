<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * [SEC-1 / P1-11] Kiosk Sanctum tokens must expire and be refreshable.
 *
 * Risk closed: a leaked kiosk token granted unbounded order creation on the
 * branch. Tokens are now issued with a 30-day TTL (config sanctum.kiosk_expiration)
 * and renewable via /api/auth/kiosk-refresh-token without re-credentialing.
 *
 * Invariants verified:
 *   1. Kiosk login writes a non-null `expires_at` on personal_access_tokens.
 *   2. The TTL written matches config('sanctum.kiosk_expiration'), NOT the
 *      tighter global config('sanctum.expiration') used by admin/POS.
 *   3. Kiosk tokens survive past the global 8h Sanctum window — proves the
 *      authenticateAccessTokensUsing() callback bypasses it for kiosk:order.
 *   4. Past `expires_at`, the token is rejected by auth:sanctum (401).
 *   5. /kiosk-refresh-token mints a new token with kiosk:order ability,
 *      a fresh expires_at, and never wildcards the abilities.
 *   6. Refresh hard-deletes the prior personal_access_tokens row.
 *   7. Past expiration, /kiosk-refresh-token itself returns 401 — the kiosk
 *      must re-authenticate via /kiosk-login.
 *   8. /kiosk-refresh-token rejects tokens that lack kiosk:order ability
 *      (CheckAbilities middleware path, 403).
 *   9. /kiosk-refresh-token rejects wildcard-ability tokens whose user has
 *      no linked KioskMachine (controller-level guard against admin →
 *      kiosk token escalation, 403).
 *  10. /kiosk-refresh-token rejects an authenticated kiosk whose KioskMachine
 *      has been deactivated since login (403).
 */
class KioskTokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
    }

    /**
     * Build a branch + linked user + active kiosk machine and authenticate by
     * hitting the real /kiosk-login endpoint. Returns the plain text token and
     * the freshly persisted PersonalAccessToken row so tests can assert on
     * expires_at and abilities directly.
     *
     * @return array{0: KioskMachine, 1: User, 2: string, 3: PersonalAccessToken}
     */
    private function loginKiosk(string $username = null, string $password = '123456'): array
    {
        $username = $username ?? 'kiosk_'.uniqid();

        $branch = Branch::forceCreate([
            'name'      => 'HQ',
            'email'     => 'hq_'.uniqid().'@foodking.test',
            'phone'     => '0102030405',
            'latitude'  => '0',
            'longitude' => '0',
            'city'      => 'Paris',
            'state'     => 'IDF',
            'zip_code'  => '75000',
            'address'   => '1 rue Test',
            'status'    => 1,
        ]);

        $user = User::factory()->create([
            'username'  => 'usr_'.uniqid(),
            'branch_id' => $branch->id,
            'status'    => Status::ACTIVE,
        ]);

        $kiosk = KioskMachine::forceCreate([
            'machine_id' => 'mach_'.uniqid(),
            'username'   => $username,
            'password'   => bcrypt($password),
            'branch_id'  => $branch->id,
            'user_id'    => $user->id,
            'is_login'   => Ask::NO,
            'status'     => Status::ACTIVE,
        ]);

        $response = $this->postJson('/api/auth/kiosk-login', [
            'username' => $username,
            'password' => $password,
        ]);
        $response->assertStatus(201);

        $plain = $response->json('token');
        $this->assertNotEmpty($plain, 'Login must return a plain text token');

        // Locate the freshly created token row to inspect expires_at.
        $accessToken = PersonalAccessToken::findToken($plain);
        $this->assertInstanceOf(PersonalAccessToken::class, $accessToken);

        return [$kiosk->fresh(), $user->fresh(), $plain, $accessToken];
    }

    /**
     * Flush in-memory auth state between successive postJson() calls. Without
     * this, the web guard's session user from the prior request persists into
     * the next call (a test-only artefact — production requests are stateless),
     * which would mask the absence of the bearer token we're trying to reject.
     */
    private function flushAuthState(): void
    {
        Auth::guard('web')->logout();
        Auth::guard('sanctum')->forgetUser();
        // Clear the web session entirely so no remember cookie / session id
        // bleeds through into the next test request.
        if ($this->app->bound('session.store')) {
            $this->app['session.store']->flush();
        }
    }

    public function test_kiosk_login_persists_30_day_expires_at(): void
    {
        [, , , $accessToken] = $this->loginKiosk();

        // [SEC-1] expires_at must be populated — not null, not the 8h global default.
        $this->assertNotNull($accessToken->expires_at, 'Kiosk token must have a non-null expires_at');

        $expectedTtl = (int) config('sanctum.kiosk_expiration');
        $this->assertSame(60 * 24 * 30, $expectedTtl, 'Default kiosk TTL must be 30 days');

        $expectedAt = Carbon::parse($accessToken->created_at)->addMinutes($expectedTtl);
        // Allow a 5-second drift between createToken's now() and our reread.
        $this->assertTrue(
            $accessToken->expires_at->diffInSeconds($expectedAt) <= 5,
            'expires_at must equal created_at + kiosk_expiration minutes'
        );

        // Negative assertion: the kiosk TTL is NOT the global staff TTL (8h).
        // This guards against a future regression where someone "consolidates"
        // both keys back into the global expiration.
        $globalTtl = (int) config('sanctum.expiration', 480);
        $this->assertNotSame($globalTtl, $expectedTtl, 'Kiosk TTL must diverge from global staff TTL');
    }

    public function test_kiosk_token_survives_past_global_8h_window(): void
    {
        // [LOAD-BEARING] This is the test that proves the
        // Sanctum::authenticateAccessTokensUsing() callback works.
        //
        // Sanctum's Guard enforces TWO checks: the global window
        // (config('sanctum.expiration')=480m for staff) AND the column-level
        // expires_at. Without our callback, kiosk tokens would still die at
        // 480m even though the column says 30d. With the callback, kiosk
        // tokens (carrying kiosk:order) bypass the global window and only
        // honour the column.
        //
        // We travel 24h forward — comfortably past the 480m global TTL but
        // well within the 30-day kiosk TTL. The token MUST still authenticate.
        [, , $plain] = $this->loginKiosk();

        $this->flushAuthState();
        Carbon::setTestNow(now()->addHours(24));

        $resp = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/auth/kiosk-refresh-token');

        Carbon::setTestNow();
        $resp->assertStatus(200);
    }

    public function test_kiosk_token_expires_after_30_days(): void
    {
        [, , $plain] = $this->loginKiosk();

        // First refresh validates the token while still alive — exercises the
        // happy path before the time-jump. Refresh mints a new token whose
        // expires_at is created fresh "now" relative to the test clock.
        $okResp = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/auth/kiosk-refresh-token');
        $okResp->assertStatus(200);

        $newToken = $okResp->json('token');
        $this->assertNotEmpty($newToken);

        // Travel past the 30-day TTL. The Sanctum callback returns false
        // because the column-level expires_at is in the past.
        $this->flushAuthState();
        Carbon::setTestNow(now()->addMinutes(60 * 24 * 30 + 1));

        $expiredResp = $this->withHeader('Authorization', 'Bearer '.$newToken)
            ->postJson('/api/auth/kiosk-refresh-token');

        Carbon::setTestNow(); // reset before any assertion to avoid clock leak
        $expiredResp->assertStatus(401);
    }

    public function test_kiosk_can_refresh_token_with_valid_current_token(): void
    {
        [$kiosk, $user, $plain, $oldAccessToken] = $this->loginKiosk();

        $resp = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/auth/kiosk-refresh-token');

        $resp->assertStatus(200)
            ->assertJsonStructure(['message', 'token', 'expires_at']);

        $newPlain = $resp->json('token');
        $this->assertNotEmpty($newPlain);
        $this->assertNotSame($plain, $newPlain, 'Refresh must mint a different token');

        // The new token row must exist with kiosk:order ability and a fresh expires_at.
        $newAccess = PersonalAccessToken::findToken($newPlain);
        $this->assertInstanceOf(PersonalAccessToken::class, $newAccess);
        $this->assertSame($user->id, (int) $newAccess->tokenable_id);
        $this->assertSame('kiosk-token', $newAccess->name);
        $this->assertContains('kiosk:order', $newAccess->abilities);
        $this->assertNotContains('*', $newAccess->abilities, 'Refresh must NOT mint wildcard ability');
        $this->assertNotNull($newAccess->expires_at);
    }

    public function test_refresh_revokes_previous_token(): void
    {
        [, , $oldPlain, $oldAccessToken] = $this->loginKiosk();
        $oldId = $oldAccessToken->id;

        $resp = $this->withHeader('Authorization', 'Bearer '.$oldPlain)
            ->postJson('/api/auth/kiosk-refresh-token');
        $resp->assertStatus(200);

        $newPlain = $resp->json('token');
        $this->assertNotSame($oldPlain, $newPlain, 'Refresh must mint a different plain text token');

        // Old token row must be deleted.
        $this->assertNull(
            PersonalAccessToken::find($oldId),
            'Refresh must hard-delete the prior personal_access_tokens row'
        );

        // Sanctum's findToken() must no longer resolve the old plain text.
        $this->assertNull(
            PersonalAccessToken::findToken($oldPlain),
            'Old plain text must not resolve to any active token row'
        );

        // Old plain text must no longer authenticate (real-world replay).
        $this->flushAuthState();
        $reuseAttempt = $this->withHeader('Authorization', 'Bearer '.$oldPlain)
            ->postJson('/api/auth/kiosk-refresh-token');
        $reuseAttempt->assertStatus(401);
    }

    public function test_kiosk_cannot_refresh_token_after_expiration(): void
    {
        [, , $plain] = $this->loginKiosk();

        // Flush auth state and travel past the 30-day TTL.
        $this->flushAuthState();
        Carbon::setTestNow(now()->addMinutes(60 * 24 * 30 + 1));

        $resp = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/auth/kiosk-refresh-token');

        Carbon::setTestNow();
        $resp->assertStatus(401);
    }

    public function test_refresh_endpoint_rejects_token_without_kiosk_order_ability(): void
    {
        // Build a user with a token whose abilities DO NOT include kiosk:order
        // and are NOT wildcard. This rides the `abilities:kiosk:order`
        // middleware path: CheckAbilities calls $user->tokenCan('kiosk:order')
        // which returns false because (a) the token has no '*' (wildcard would
        // satisfy named ability checks per PersonalAccessToken::can()) and
        // (b) 'kiosk:order' is not in the explicit abilities array.
        // The middleware throws MissingAbilityException → 403.
        $user = User::factory()->create([
            'username' => 'staff_'.uniqid(),
            'status'   => Status::ACTIVE,
        ]);
        $plain = $user->createToken('non_kiosk_token', ['profile:read'])->plainTextToken;

        $resp = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/auth/kiosk-refresh-token');

        $resp->assertStatus(403);
    }

    public function test_refresh_endpoint_rejects_wildcard_token_without_linked_kiosk_machine(): void
    {
        // Defense-in-depth: even if a token carries the '*' ability (admin
        // login mints these via /api/auth/login), reaching the controller body
        // with a user that has no linked KioskMachine row must still 403. This
        // protects against a scenario where an admin's leaked token gets
        // POSTed at /kiosk-refresh-token: the abilities middleware lets the
        // wildcard through (Sanctum's PersonalAccessToken::can() treats '*' as
        // satisfying any named ability), but the controller's KioskMachine
        // lookup returns null and rejects with 403.
        //
        // If this test ever flips to 200, the controller's kiosk-machine
        // guard has been removed and the endpoint has become an admin token
        // → kiosk token escalation vector. That is a SEC regression.
        $user = User::factory()->create([
            'username' => 'admin_'.uniqid(),
            'status'   => Status::ACTIVE,
        ]);
        $plain = $user->createToken('auth_token', ['*'])->plainTextToken;

        $resp = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/auth/kiosk-refresh-token');

        $resp->assertStatus(403);
    }

    public function test_refresh_endpoint_rejects_unauthenticated_calls(): void
    {
        // No bearer token at all → auth:sanctum 401.
        $resp = $this->postJson('/api/auth/kiosk-refresh-token');
        $resp->assertStatus(401);
    }

    public function test_refresh_rejects_inactive_kiosk_machine(): void
    {
        [$kiosk, $user, $plain] = $this->loginKiosk();

        // Operator deactivates the kiosk in admin while the token is still alive.
        $kiosk->update(['status' => Status::INACTIVE]);

        $resp = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/auth/kiosk-refresh-token');

        $resp->assertStatus(403);
    }
}
