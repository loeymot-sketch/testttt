<?php

namespace Tests\Feature\Sentinels;

use App\Models\Branch;
use App\Models\User;
use App\Services\Loyalty\LoyaltyQrSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * @FK-ID FK-LOYALTY-QR-SIGNING-LCS-S-001
 * @source Loyalty cross-surface audit, round-1 STATUS.md (2026-05-18)
 *
 * Sentinel : signed loyalty QR scan must verify HMAC + TTL + nonce
 * server-side, and refuse expired / tampered / replayed payloads with
 * a machine-readable `error_code` while keeping HTTP 200 (per the
 * existing /loyalty/scan §12 invariant : a failed scan MUST NOT block
 * the parcours client). Backward compat with legacy plaintext FK:<code>
 * is REQUIRED during the transition window (mobile cycle deferred).
 *
 * Anti-régression : if anyone weakens signature verification, drops the
 * nonce consumption, accepts expired tokens, or breaks the legacy
 * plaintext path while it's enabled, this sentinel breaks immediately.
 */
class LoyaltyQrSigningSentinelTest extends TestCase
{
    use RefreshDatabase;

    private function setupKioskAuth(): array
    {
        $this->seedMinimalSettings();

        $branch = Branch::updateOrCreate(['id' => 1], [
            'name'      => 'HQ',
            'email'     => 'hq@foodking.com',
            'phone'     => '123',
            'latitude'  => '0',
            'longitude' => '0',
            'city'      => 'Paris',
            'state'     => 'IDF',
            'zip_code'  => '75',
            'address'   => '1 rue',
        ]);

        $kioskUser = User::factory()->create([
            'username'  => 'kiosk_loyalty_' . uniqid(),
            'branch_id' => $branch->id,
        ]);

        // Status = Status::ACTIVE so EnsureUserStatusActive (Z6-06) passes
        // when the customer authenticates to /loyalty/qr generation. The
        // /loyalty/scan controller accepts BOTH legacy 1 and Status::ACTIVE
        // via the new isCustomerActive() helper introduced in this commit
        // (alignment heal — was the only place still using status==1).
        $customer = User::factory()->create([
            'username'      => 'cust_' . uniqid(),
            'branch_id'     => $branch->id,
            'loyalty_code'  => 'ABCD1234',
            'loyalty_points'=> 200,
            'name'          => 'Alice',
            'status'        => \App\Enums\Status::ACTIVE,
        ]);

        $kioskToken = $kioskUser->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;
        $customerToken = $customer->createToken('customer-token')->plainTextToken;

        // Stable test secret (≥32 chars) — production guard is tested separately.
        config(['loyalty.qr.secret' => str_repeat('a', 32)]);
        config(['loyalty.qr.ttl_seconds' => 300]);
        config(['loyalty.qr.leeway_seconds' => 30]);
        config(['loyalty.qr.accept_legacy_plaintext' => true]);

        return [$kioskUser, $kioskToken, $customer, $customerToken];
    }

    public function test_valid_signed_token_scan_succeeds_with_signed_header(): void
    {
        [, $kioskToken, $customer] = $this->setupKioskAuth();

        $signed = app(LoyaltyQrSigner::class)->sign($customer->id, $customer->loyalty_code);

        $response = $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
            ->postJson('/api/frontend/loyalty/scan', [
                'method'   => 'qr',
                'raw_data' => $signed['token'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.error_code', null)
            ->assertJsonPath('data.loyalty_balance_points', 200)
            ->assertHeader('X-Loyalty-QR-Status', 'signed');

        $this->assertDatabaseHas('loyalty_qr_nonces_consumed', [
            'nonce'       => $signed['nonce'],
            'customer_id' => $customer->id,
        ]);
    }

    public function test_expired_signed_token_is_rejected_with_qr_expired_error_code(): void
    {
        [, $kioskToken, $customer] = $this->setupKioskAuth();

        // Sign with a clock 1 hour in the past — leeway is only 30s so this MUST expire.
        $pastClock = Carbon::now()->subHour();
        $signed = app(LoyaltyQrSigner::class)->sign(
            $customer->id,
            $customer->loyalty_code,
            $pastClock,
        );

        $response = $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
            ->postJson('/api/frontend/loyalty/scan', [
                'method'   => 'qr',
                'raw_data' => $signed['token'],
            ]);

        // HTTP 200 invariant preserved — surface failure via error_code only.
        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error_code', 'qr_expired');

        // Nonce must NOT have been consumed (token never reached the insert step).
        $this->assertDatabaseMissing('loyalty_qr_nonces_consumed', [
            'nonce' => $signed['nonce'],
        ]);
    }

    public function test_tampered_hmac_is_rejected_with_qr_invalid_signature(): void
    {
        [, $kioskToken, $customer] = $this->setupKioskAuth();

        $signed = app(LoyaltyQrSigner::class)->sign($customer->id, $customer->loyalty_code);

        // Replace the HMAC segment entirely with a constant-shape, wrong-value
        // signature. base64url(0x00 * 32) = 43 chars unpadded — same length as
        // a real SHA-256 HMAC so any check that gates on shape still parses,
        // but hash_equals fails. Robust against accidental "flipped char ==
        // original due to base64 padding noise".
        $token = $signed['token'];
        $parts = explode('.', $token, 3);
        $fakeHmac = rtrim(strtr(base64_encode(str_repeat("\0", 32)), '+/', '-_'), '=');
        $tampered = $parts[0] . '.' . $parts[1] . '.' . $fakeHmac;

        $response = $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
            ->postJson('/api/frontend/loyalty/scan', [
                'method'   => 'qr',
                'raw_data' => $tampered,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error_code', 'qr_invalid_signature');

        $this->assertDatabaseMissing('loyalty_qr_nonces_consumed', [
            'nonce' => $signed['nonce'],
        ]);
    }

    public function test_replayed_signed_token_is_rejected_with_qr_replay(): void
    {
        [, $kioskToken, $customer] = $this->setupKioskAuth();

        $signed = app(LoyaltyQrSigner::class)->sign($customer->id, $customer->loyalty_code);

        // First scan : success, nonce consumed.
        $first = $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
            ->postJson('/api/frontend/loyalty/scan', [
                'method'   => 'qr',
                'raw_data' => $signed['token'],
            ]);
        $first->assertStatus(200)->assertJsonPath('data.ok', true);

        // Second scan with the SAME token : replay attack — must be refused.
        $second = $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
            ->postJson('/api/frontend/loyalty/scan', [
                'method'   => 'qr',
                'raw_data' => $signed['token'],
            ]);

        $second->assertStatus(200)
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error_code', 'qr_replay');

        // Still exactly one row for that nonce.
        $this->assertSame(1, \DB::table('loyalty_qr_nonces_consumed')
            ->where('nonce', $signed['nonce'])
            ->count());
    }

    public function test_legacy_plaintext_fk_prefix_still_accepted_with_legacy_header(): void
    {
        [, $kioskToken, $customer] = $this->setupKioskAuth();

        $response = $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
            ->postJson('/api/frontend/loyalty/scan', [
                'method'   => 'qr',
                'raw_data' => 'FK:' . $customer->loyalty_code,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.loyalty_balance_points', 200)
            ->assertJsonPath('data.error_code', null)
            ->assertHeader('X-Loyalty-QR-Status', 'legacy-plaintext');
    }

    public function test_generate_qr_endpoint_requires_authentication(): void
    {
        $this->setupKioskAuth(); // seeds settings + sets test secret

        // No Authorization header → auth:sanctum middleware must reject.
        $response = $this->postJson('/api/frontend/loyalty/qr');

        $response->assertStatus(401);
    }

    public function test_generate_qr_endpoint_returns_signed_token_that_round_trips_through_scan(): void
    {
        [, $kioskToken, $customer, $customerToken] = $this->setupKioskAuth();

        // 1. Customer mints a fresh QR via /api/frontend/loyalty/qr.
        //    Use the bearer token path (same as the /scan tests above) —
        //    actingAs(..., 'sanctum') trips the EnsureUserStatusActive
        //    middleware's status==ACTIVE(=5) check, while the bearer path
        //    used by the existing LoyaltyController callsites does not in
        //    the same way (kept consistent with our /scan invocations to
        //    rule out the auth bridge as a confounder).
        $mint = $this->withHeaders(['Authorization' => "Bearer {$customerToken}"])
            ->postJson('/api/frontend/loyalty/qr');

        $mint->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.loyalty_code', $customer->loyalty_code)
            ->assertJsonPath('data.ttl_seconds', 300)
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'ttl_seconds', 'loyalty_code']]);

        $token = $mint->json('data.token');
        $this->assertIsString($token);
        $this->assertStringStartsWith('lqr.', $token, 'token must use lqr. prefix');

        // 2. Kiosk presents the freshly-minted token to /loyalty/scan.
        $scan = $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
            ->postJson('/api/frontend/loyalty/scan', [
                'method'   => 'qr',
                'raw_data' => $token,
            ]);

        $scan->assertStatus(200)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.error_code', null)
            ->assertHeader('X-Loyalty-QR-Status', 'signed');
    }

    public function test_generate_qr_mints_loyalty_code_for_customer_without_one(): void
    {
        $this->setupKioskAuth();

        // Customer with no loyalty_code yet — the endpoint must mint one
        // on demand so the response is never empty for an authenticated user.
        $newCustomer = User::factory()->create([
            'username'     => 'no_code_' . uniqid(),
            'branch_id'    => 1,
            'loyalty_code' => null,
            'status'       => \App\Enums\Status::ACTIVE,
        ]);
        $token = $newCustomer->createToken('cust-no-code')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/frontend/loyalty/qr');

        $response->assertStatus(200)
            ->assertJsonPath('status', true);

        $loyaltyCode = $response->json('data.loyalty_code');
        $this->assertIsString($loyaltyCode);
        $this->assertNotSame('', $loyaltyCode, 'loyalty_code must be minted on demand');

        // Persisted on the user row so subsequent calls see it.
        $newCustomer->refresh();
        $this->assertSame($loyaltyCode, $newCustomer->loyalty_code);
    }

    public function test_production_boot_guard_refuses_empty_loyalty_qr_secret(): void
    {
        $this->app['env'] = 'production';
        config(['loyalty.qr.secret' => '']);

        // Neutralize sibling production guards so we only assert on our own.
        // [TEST-E2E-HEAL 2026-07-15] inclut le guard Carnet DAILY_BOOK_PIN (défaut 2468) qui
        // sinon préempte le guard LOYALTY_QR_SECRET sous test.
        config(['daily_book.pin' => '9137']);
        config(['pos.simulation_hardware' => false]);
        config(['payment.bypass.enabled' => false]);
        config(['printing.bypass.enabled' => false]);
        config(['app.debug' => false]);
        config(['idempotency.enabled' => true]);
        config(['app.url' => 'https://example.test']);
        config(['broadcasting.default' => 'pusher']);
        config(['queue.default' => 'redis']);
        config(['cache.default' => 'redis']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/LOYALTY_QR_SECRET must be set in production/');

        $provider = new \App\Providers\AppServiceProvider($this->app);
        $provider->boot();
    }
}
