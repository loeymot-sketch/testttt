<?php

namespace Tests\Feature\Security;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * @see app/Http/Middleware/BlockKioskTokenFromAdminRoutes.php
 * @see reports/test-e2e/goal-2026-05-23/phase-j/J-ADV-6-trust-escalation-findings.json
 * @see CLAUDE.md §9 — Sanctum kiosk:order ability discipline
 *
 * Phase J-ADV-6 PATH-1 RED P0 reproduced empirically in the adversarial
 * audit: a Sanctum::actingAs($admin, ['kiosk:order']) request to
 * GET /api/admin/pos-order returned 200 with {data:[]} payload because
 * Spatie's permission:pos-orders|pos middleware checked
 * Auth::user()->can() and the kiosk machine was bound to the admin
 * user — token abilities were NEVER consulted.
 *
 * This sentinel baseline-locks the J2-HEAL-02 fix:
 *   - kiosk-only ability token MUST be refused with 403 +
 *     token_ability_insufficient on any /api/admin/* route.
 *   - wildcard '*' ability token MUST pass through the new middleware
 *     (we expect 200 OK from the legitimate admin index endpoint).
 *
 * NOTE: do NOT use WithoutMiddleware here — the whole point of the
 * sentinel is that the new middleware runs.
 */
class KioskTokenAdminBlockSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders([
            'x-api-key' => 'test-api-key',
            'Accept'    => 'application/json',
        ]);
    }

    /**
     * RED reproduction → GREEN fix.
     *
     * Admin user (Admin role, all Spatie permissions) gets a Sanctum
     * token with ONLY the kiosk:order ability — mirroring what the
     * KioskMachineLoginController hands the borne when KioskMachine is
     * bound to admin@lecayenne.fr (default Le Cayenne config).
     *
     * Before J2-HEAL-02 this returned 200 with the order index payload.
     * After J2-HEAL-02 the new middleware MUST short-circuit with 403
     * and the structured token_ability_insufficient error code.
     */
    public function test_kiosk_only_token_is_blocked_from_admin_pos_order_index(): void
    {
        $branch = Branch::forceCreate([
            'name'     => 'Test Branch JADV6 RED',
            'city'     => 'Paris',
            'state'    => 'IDF',
            'zip_code' => '75000',
            'address'  => '1 rue J-ADV-6',
            'status'   => 1,
        ]);

        // Mirror the J-ADV-6 escalation precondition: kiosk machine bound
        // to the admin user (branch_id=0 = global, Admin role).
        $admin = User::forceCreate([
            'name'      => 'Admin J-ADV-6',
            'email'     => 'admin-jadv6@example.test',
            'username'  => 'admin-jadv6',
            'password'  => bcrypt('password-test-only'),
            'status'    => Status::ACTIVE,
            'branch_id' => 0,
        ]);
        $admin->assignRole('Admin');

        // The bug: token carries ONLY ['kiosk:order'] but the underlying
        // user is Admin with the 'pos-orders' Spatie permission. Pre-fix
        // the request reached the controller. Post-fix the middleware
        // refuses at the route boundary.
        Sanctum::actingAs($admin, ['kiosk:order']);

        $response = $this->getJson('/api/admin/pos-order');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'token_ability_insufficient',
        ]);
    }

    /**
     * Positive control — wildcard '*' token (real admin session-or-token)
     * must NOT be blocked by the new middleware. Anything other than the
     * canonical 200 still proves the middleware over-restricted (we'd see
     * 403 + token_ability_insufficient, which would be the regression).
     *
     * We only assert that the response is NOT the kiosk-block 403. The
     * controller is allowed to legitimately return 200/422/etc. depending
     * on downstream logic — what matters is that BlockKioskTokenFromAdminRoutes
     * let the request through.
     */
    public function test_wildcard_admin_token_is_not_blocked_by_kiosk_guard(): void
    {
        $branch = Branch::forceCreate([
            'name'     => 'Test Branch JADV6 GREEN',
            'city'     => 'Paris',
            'state'    => 'IDF',
            'zip_code' => '75000',
            'address'  => '2 rue J-ADV-6',
            'status'   => 1,
        ]);

        $admin = User::forceCreate([
            'name'      => 'Admin J-ADV-6 wildcard',
            'email'     => 'admin-jadv6-wc@example.test',
            'username'  => 'admin-jadv6-wc',
            'password'  => bcrypt('password-test-only'),
            'status'    => Status::ACTIVE,
            'branch_id' => 0,
        ]);
        $admin->assignRole('Admin');

        // Sanctum::actingAs with no abilities array defaults to ['*'] —
        // the canonical "full admin" token shape.
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/pos-order');

        $this->assertNotSame(
            403,
            $response->status(),
            'Wildcard admin token must not be blocked by BlockKioskTokenFromAdminRoutes.'
        );
        // Specifically: we MUST NOT see the kiosk-block error code.
        $payload = $response->json();
        if (is_array($payload) && array_key_exists('error', $payload)) {
            $this->assertNotSame(
                'token_ability_insufficient',
                $payload['error'],
                'Wildcard admin token wrongly triggered the kiosk-only block.'
            );
        }
    }
}
