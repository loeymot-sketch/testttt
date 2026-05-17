<?php

namespace Tests\Feature\Auth;

use App\Enums\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [Sprint H1 Z6-02 2026-05-17] Guest signup must mint a token scoped to
 * `kiosk:order` ability only — not the wildcard `*`.
 *
 * CLAUDE.md §9 (Sanctum kiosk:order) requires that tokens issued to
 * customer-facing flows (kiosk, guest web) carry the minimal ability set
 * needed to satisfy `tokenCan('kiosk:order')` and nothing more. A leaked
 * guest token with `['*']` ability would unlock admin endpoints (e.g.
 * `/api/admin/*` routes that gate on `auth:sanctum` + `permission:*`
 * combinations), because Sanctum's wildcard short-circuits ability checks.
 *
 * Evidence the narrow scope is sufficient:
 *   `app/Http/Requests/OrderRequest.php:46-47` documents that BOTH
 *   `['*']` (legacy GuestSignup) AND `['kiosk:order']` (KioskMachineLogin)
 *   satisfy `tokenCan('kiosk:order')`. Guest UI calls only the kiosk-order
 *   subset of `/api/frontend/*` — confirmed by grep of guest UI sources.
 */
class GuestSignupAbilityScopeTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        // GuestSignupController::register() depends on three Settings rows
        // not covered by seedMinimalSettings. Insert them explicitly so the
        // guest path reaches the token-mint branch deterministically.
        DB::table('settings')->insert([
            // Allow guest login (else 422 "guest_login_is_not_allowed").
            ['key' => 'site_guest_login',        'payload' => json_encode(Activity::ENABLE),  'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            // Disable OTP enforcement so verify() falls through to register().
            ['key' => 'site_phone_verification', 'payload' => json_encode(Activity::DISABLE), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            // Default branch id used when user.branch_id == 0 (guest case).
            ['key' => 'site_default_branch',     'payload' => json_encode(1),                 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
        ]);

        config(['app.api_key' => self::API_KEY]);

        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);
    }

    /** @test */
    public function test_guest_signup_issues_kiosk_order_ability_only(): void
    {
        // VerifyPhoneRequest requires: code (max:32), phone (ValidPhone — FR
        // national 9-15 digits OR E.164 +XX...), token (max:180).
        $payload = [
            'code'  => '+33',
            'phone' => '0612345678',
            'token' => 'test-signup-token',
        ];

        $response = $this->postJson('/api/auth/guest-signup/verify', $payload);

        // GuestSignupController::register() returns 201 Created on success.
        $response->assertStatus(201);
        $response->assertJsonStructure(['token']);

        $user = User::where('phone', '0612345678')->first();
        $this->assertNotNull($user, 'Guest user should be created by /verify path.');

        $token = $user->tokens()->where('name', 'auth_token')->latest('id')->first();
        $this->assertNotNull($token, 'Guest auth_token row should exist in personal_access_tokens.');

        // THE LOAD-BEARING ASSERTION (Z6-02):
        // Token ability MUST be ['kiosk:order'] only — never wildcard.
        $this->assertSame(
            ['kiosk:order'],
            $token->abilities,
            'Guest token ability must be scoped to kiosk:order — wildcard would let a leaked token reach admin endpoints.'
        );
        $this->assertNotContains(
            '*',
            $token->abilities,
            'Guest token must NEVER include wildcard ability (CLAUDE.md §9).'
        );
    }
}
