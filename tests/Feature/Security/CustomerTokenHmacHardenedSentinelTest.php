<?php

namespace Tests\Feature\Security;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Sentinel — GOAL-J2-HEAL-03 2026-05-24
 *   Phase J-ADV-7 HC-003 P0 (customer_token HMAC + entropy)
 *   Phase J-ADV-1 ADV1-V01 P1 (legacy plaintext default flip)
 *
 * Closes two findings from the Phase J adversarial security audit on the
 * /api/frontend/loyalty/scan response payload.
 *
 *   FINDING 1 — J-ADV-7 HC-003 P0
 *   Before this heal, app/Http/Controllers/Frontend/LoyaltyController.php
 *   built `customer_token` as:
 *
 *       'lt_' . substr(
 *           hash('sha256', $userId . '|' . time() . '|' . APP_KEY),
 *           0, 32
 *       )
 *
 *   Problems:
 *     • No HMAC despite LOYALTY_QR_SECRET existing for QR signing.
 *     • Second-resolution `time()` → predictable enumeration window.
 *     • Truncated to 128 bits (32 hex chars) — weaker than full SHA-256.
 *
 *   Fix:
 *     • hash_hmac('sha256', $payload, config('loyalty.qr.secret') ?: APP_KEY)
 *       — mirrors LoyaltyQrSigner pattern.
 *     • $payload includes bin2hex(random_bytes(16)) — defeats timestamp
 *       prediction and guarantees uniqueness across same-second calls.
 *     • Full 256-bit output (64 hex chars, plus `lt_` prefix → 67 chars).
 *
 *   FINDING 2 — J-ADV-1 ADV1-V01 P1
 *   `LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT` defaulted to TRUE → an attacker
 *   with an 8-char loyalty_code could POST raw `FK:<code>` and harvest
 *   customer_token + display_name + loyalty_balance_points (PII leak).
 *   Default flipped to FALSE; legacy clients can explicitly opt-in.
 *
 * This sentinel locks BOTH heals so any regression breaks CI immediately.
 *
 * @see app/Http/Controllers/Frontend/LoyaltyController.php (~ln 702-723)
 * @see config/loyalty.php (~ln 62-78)
 * @see CLAUDE.md §9 Auth Invariants
 */
class CustomerTokenHmacHardenedSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Setup branch + customer + kiosk user. Mirrors LoyaltyQrSigningSentinelTest
     * pattern so the /loyalty/scan endpoint is reachable end-to-end and the
     * `customer_token` field on the response is the real production code path
     * (not a mock).
     *
     * @return array{0: User, 1: string, 2: User}
     */
    private function setupScanContext(): array
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
            'username'  => 'kiosk_token_sentinel_' . uniqid(),
            'branch_id' => $branch->id,
        ]);

        $customer = User::factory()->create([
            'username'      => 'cust_token_sentinel_' . uniqid(),
            'branch_id'     => $branch->id,
            'loyalty_code'  => 'TKNHMAC1',
            'loyalty_points'=> 42,
            'name'          => 'Bob',
            'status'        => Status::ACTIVE,
        ]);

        $kioskToken = $kioskUser->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

        // Stable test secret (≥32 chars) so HMAC has a real key in tests.
        config(['loyalty.qr.secret' => str_repeat('a', 32)]);
        config(['loyalty.qr.ttl_seconds' => 300]);
        config(['loyalty.qr.leeway_seconds' => 30]);

        return [$kioskUser, $kioskToken, $customer];
    }

    /**
     * (Finding 2) — config default MUST be FALSE.
     *
     * If a future PR flips the default back to TRUE without an explicit
     * security review, this sentinel breaks the build.
     */
    public function test_accept_legacy_plaintext_defaults_to_false(): void
    {
        // Re-read the config WITHOUT the env override. We bypass the live
        // env() resolution by clearing the cached config value and forcing
        // the package's own default-chain evaluation: filter_var(env(...,
        // false)) ?? false. In phpunit.xml the env is unset, so the only
        // path to TRUE here would be a code-level default flip.
        config()->set('loyalty.qr.accept_legacy_plaintext', null);
        $reloaded = require base_path('config/loyalty.php');

        $this->assertFalse(
            $reloaded['qr']['accept_legacy_plaintext'],
            'config/loyalty.php MUST default accept_legacy_plaintext=false '
            . 'to prevent customer_token + name + balance harvest via an '
            . '8-char loyalty_code (J-ADV-1 ADV1-V01 P1).'
        );
    }

    /**
     * (Finding 2) — env=true STILL allows opt-in.
     *
     * The flip is a default change, not a feature removal. Environments
     * still running pre-signed-QR mobile clients must be able to opt back
     * in via LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=true. This sentinel locks
     * that escape hatch.
     */
    public function test_accept_legacy_plaintext_env_true_still_opts_in(): void
    {
        // Simulate env override → filter_var(true) → true.
        $envBefore = getenv('LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT');
        putenv('LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=true');

        try {
            $reloaded = require base_path('config/loyalty.php');

            $this->assertTrue(
                $reloaded['qr']['accept_legacy_plaintext'],
                'env LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=true MUST still '
                . 'enable the legacy plaintext path (migration escape '
                . 'hatch for pre-signed-QR clients).'
            );
        } finally {
            if ($envBefore === false) {
                putenv('LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT');
            } else {
                putenv('LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=' . $envBefore);
            }
        }
    }

    /**
     * (Finding 1) — 1000 tokens for the same customer MUST all be unique.
     *
     * random_bytes(16) inside the HMAC payload defeats timestamp collisions:
     * two scans of the same loyalty_code within the same second now produce
     * cryptographically-distinct tokens, blocking enumeration via observed
     * timestamps.
     */
    public function test_thousand_tokens_for_same_customer_are_all_unique(): void
    {
        [, $kioskToken, $customer] = $this->setupScanContext();

        // Enable legacy plaintext for this specific test only — we're not
        // testing the QR signing surface, we want the cheapest scan path
        // that still emits a real `customer_token` from the controller.
        config(['loyalty.qr.accept_legacy_plaintext' => true]);

        // 1000 successive scans would trip the global /api/* throttle
        // (config app.api_throttle_per_minute default 120). Skip ONLY
        // ThrottleRequests — every other middleware (auth, kiosk, status)
        // still runs, and the actual customer_token generation code path
        // is unchanged. We're measuring entropy of the HMAC payload, not
        // throttle behaviour.
        $this->withoutMiddleware(ThrottleRequests::class);

        $tokens = [];
        $iterations = 1000;

        for ($i = 0; $i < $iterations; $i++) {
            $response = $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
                ->postJson('/api/frontend/loyalty/scan', [
                    'method'   => 'qr',
                    'raw_data' => 'FK:' . $customer->loyalty_code,
                ]);

            $response->assertStatus(200)
                ->assertJsonPath('data.ok', true);

            $token = $response->json('data.customer_token');
            $this->assertIsString($token, "Iteration {$i}: customer_token missing.");
            $tokens[] = $token;
        }

        $unique = array_unique($tokens);
        $this->assertCount(
            $iterations,
            $unique,
            sprintf(
                'Generated %d customer_tokens for the SAME user but only '
                . '%d are unique — random_bytes(16) entropy missing from '
                . 'HMAC payload (J-ADV-7 HC-003 P0 regression).',
                $iterations,
                count($unique)
            )
        );
    }

    /**
     * (Finding 1) — token format MUST be `lt_` + 64 hex chars (full HMAC).
     *
     * The `lt_` prefix is part of the contract (callers may pattern-match);
     * the 64-hex output proves the HMAC is NOT truncated to 128 bits as the
     * previous SHA256-substr(0,32) implementation was.
     */
    public function test_customer_token_format_is_lt_prefix_plus_64_hex_chars(): void
    {
        [, $kioskToken, $customer] = $this->setupScanContext();
        config(['loyalty.qr.accept_legacy_plaintext' => true]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
            ->postJson('/api/frontend/loyalty/scan', [
                'method'   => 'qr',
                'raw_data' => 'FK:' . $customer->loyalty_code,
            ]);

        $response->assertStatus(200);
        $token = $response->json('data.customer_token');

        $this->assertIsString($token, 'customer_token must be a string.');
        $this->assertMatchesRegularExpression(
            '/^lt_[0-9a-f]{64}$/',
            $token,
            sprintf(
                'customer_token MUST be `lt_` + 64 lowercase-hex chars '
                . '(full HMAC-SHA256 output). Got: %s (length=%d). '
                . 'A truncated 32-char form indicates regression to the '
                . 'pre-heal weak SHA256-substr implementation '
                . '(J-ADV-7 HC-003 P0).',
                $token,
                strlen($token)
            )
        );
    }
}
