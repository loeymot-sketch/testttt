<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * [P0-5 / KIO-1] Contract tests for KioskHealthController.
 *
 * @see app/Http/Controllers/Frontend/KioskHealthController.php
 * @see plans/PRE_PROD_DEEP_RISK_AUDIT_2026-05-08.md §KIO-1 / §P0-5
 *
 * The endpoint is intentionally public (apiKey-only, NO auth:sanctum)
 * because the kiosk hits this BEFORE authenticating as a KioskMachine.
 * The boot overlay polls it on retry, so it must stay cheap and idempotent.
 */
class KioskHealthControllerTest extends TestCase
{
    /**
     * Healthy baseline : DB + cache reachable in :memory: SQLite + array
     * cache (the phpunit.xml defaults). Should respond 200 with the
     * expected JSON shape.
     */
    public function test_kiosk_health_returns_200_ok_when_all_subsystems_up(): void
    {
        $response = $this->getJson('/api/frontend/kiosk/health');

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'timestamp']);
        $this->assertSame('ok', $response->json('status'));
        $this->assertNotEmpty($response->json('timestamp'));
    }

    /**
     * The endpoint MUST be reachable without a Sanctum token — that's the
     * whole point. The kiosk runs this ping pre-login. If we accidentally
     * mount it under auth:sanctum, the boot overlay will 401-loop.
     */
    public function test_kiosk_health_does_not_require_sanctum_auth(): void
    {
        // No Sanctum token attached, only the apiKey header from setUp().
        $response = $this->getJson('/api/frontend/kiosk/health');

        $response->assertStatus(200);
        $this->assertNotSame(401, $response->status());
        $this->assertNotSame(403, $response->status());
    }

    /**
     * Burst-safe behaviour : the boot retry button can be hit repeatedly,
     * so the route allows ~60/min. We only verify the throttle does not
     * trip after a small handful of calls (a real 429 test would be flaky
     * since the limiter resets every minute).
     */
    public function test_kiosk_health_tolerates_burst_calls(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->getJson('/api/frontend/kiosk/health');
            $response->assertStatus(200);
        }
    }

    /**
     * Response shape : `status` ∈ {ok, degraded}, `timestamp` ISO8601 when ok.
     * The frontend boot service compares `data.status === 'ok'` strictly.
     */
    public function test_kiosk_health_response_uses_strict_ok_status_string(): void
    {
        $response = $this->getJson('/api/frontend/kiosk/health');

        $response->assertStatus(200);
        // Strict check : must be exactly 'ok', not 'OK' or true — the
        // frontend boot service compares with `===`.
        $this->assertSame('ok', $response->json('status'));
    }
}
