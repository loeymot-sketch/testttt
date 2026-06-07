<?php

namespace Tests\Feature\Loyalty;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [CENTRAL-03 2026-06-07] Anti-enumeration parity on the loyalty lookup surface.
 *
 * The POST /api/frontend/loyalty/check handler carries throttle:10,1 ([AUDIT-P0-D]),
 * but the GET /api/frontend/loyalty/balance route — which calls the SAME check()
 * derivation and LAZILY MINTS a loyalty_code on lookup — shipped WITHOUT a throttle.
 * That is a mint-spam / phone-enumeration vector (relates to WP-07 PII-by-phone):
 * an authenticated actor could brute-force phone numbers through /balance with no
 * rate ceiling. Fix = add ->middleware('throttle:10,1') to /balance to match /check.
 *
 * This test asserts the REAL consequence (a 429 after the 11th call), and uses
 * /check as a POSITIVE CONTROL to prove the test environment actually enforces
 * throttling (guards against a silently-disabled limiter making this theater).
 *
 * throttle:10,1 keys by authenticated user id, so each test uses ONE fresh actor.
 */
class LoyaltyBalanceThrottleParityTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Ensure named limiters do not get globally disabled by a high test cap.
        // (throttle:10,1 is a fixed inline cap, not config-driven — but pin nothing
        // to keep the assertion deterministic against the literal 10/min.)
        $this->branch = Branch::factory()->create();
    }

    private function freshActor(string $phone): User
    {
        $u = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('password'),
            'phone'     => $phone,
        ]);
        $u->assignRole('POS Operator');
        return $u;
    }

    /**
     * POSITIVE CONTROL — /check already has throttle:10,1. If the test env did not
     * enforce throttling at all, this would never 429 and the suite would be theater.
     */
    public function test_check_post_throttles_after_ten_hits(): void
    {
        $actor = $this->freshActor('0107070701');
        $this->actingAs($actor, 'sanctum');

        $last = null;
        for ($i = 0; $i < 11; $i++) {
            $last = $this->withHeader('x-api-key', config('app.api_key'))
                ->postJson('/api/frontend/loyalty/check', ['code' => '0699999999']);
        }

        $this->assertSame(429, $last->status(), '/check must 429 after 10 hits/min (positive control).');
    }

    /**
     * THE DEFECT/FIX — /balance must reach the SAME 429 ceiling as /check.
     * Pre-fix this route had no throttle and returned 200/404 indefinitely.
     */
    public function test_balance_get_throttles_after_ten_hits(): void
    {
        $actor = $this->freshActor('0107070702');
        $this->actingAs($actor, 'sanctum');

        $last = null;
        for ($i = 0; $i < 11; $i++) {
            $last = $this->withHeader('x-api-key', config('app.api_key'))
                ->getJson('/api/frontend/loyalty/balance?code=' . urlencode('0699999998'));
        }

        $this->assertSame(
            429,
            $last->status(),
            '/balance must 429 after 10 hits/min (throttle:10,1 parity with /check) to block phone-enumeration / mint-spam.'
        );
    }
}
